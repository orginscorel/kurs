//! Yerel kip (A) çalışma zamanı: gömülü PHP sunucusu + dakikalık zamanlayıcı + çökme gözetimi.
//!
//! Sunucu seçimi: `php -S` (PHP'nin yerleşik sunucusu) + PHP_CLI_SERVER_WORKERS=4 + router.php.
//! Tek kullanıcılı masaüstünde SPA'nın eşzamanlı istekleri (pano akışı, bildirim, eşitleme göstergesi) için
//! 4 süreç yeterli; SQLite zaten tek yazıcılı. FrankenPHP (Caddy + işçi kipi) daha hızlıdır ama ikili ~2 kat büyür,
//! derlemesi Go + xcaddy ister ve imzalama/notarization yüzeyini artırır → ilk sürümde php -S, ölçüm sonrası
//! gerekirse FrankenPHP (docs/DESKTOP.md › PHP sunucusu).

use crate::paths::Paths;
use crate::php;
use crate::secrets::{self, LocalSecrets};
use serde::Serialize;
use std::collections::VecDeque;
use std::fs::OpenOptions;
use std::net::TcpListener;
use std::process::Stdio;
use std::sync::atomic::{AtomicBool, AtomicI32, AtomicU64, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};
use tauri::{AppHandle, Emitter, Manager, Runtime};
use tokio::sync::{oneshot, watch, Mutex, Notify};

const SERVER_WORKERS: &str = "4";
const HEALTH_TIMEOUT: Duration = Duration::from_secs(40);
const MAX_RESTARTS: usize = 5;
const RESTART_WINDOW: Duration = Duration::from_secs(300);
/// Eşitleme turu aralığı (zamanlayıcıdan bağımsız, bu uygulamanın kendi görevi). Anlık senkron hissi için 15 sn.
const SYNC_INTERVAL: Duration = Duration::from_secs(15);
/// Tek tur üst sınırı: aşılırsa süreç grubu öldürülür (ölü TCP bağlantısında asılı kalma)
const SYNC_TIMEOUT: Duration = Duration::from_secs(150);
/// Duvar saati tekdüze saatten bu kadar ileri kaydıysa makine uyumuştur (macOS'ta Instant uykuda ilerlemez)
const WAKE_GAP_SECS: u64 = 120;
/// Zamanlayıcının başlattığı ve hâlâ yaşayan süreç grubu bu yaştan büyükse asılı sayılır
const STUCK_GROUP_AGE: Duration = Duration::from_secs(600);
/// Push dinleyicisi kapalıyken (çıkış 0) ayarın yeniden denenme aralığı
const LISTENER_IDLE_RECHECK: Duration = Duration::from_secs(20);
/// Push dinleyicisi çöktüğünde/port açılamadığında en uzun bekleme
const LISTENER_MAX_BACKOFF: Duration = Duration::from_secs(60);

type Groups = Arc<std::sync::Mutex<VecDeque<(i32, Instant)>>>;

/// "Şimdi eşitle" ve menü için tur sonucu (kullanıcıya gösterilir).
#[derive(Clone, Serialize, Debug)]
pub struct SyncOutcome {
    /// ok | running | offline | error | revoked | unpaired | needs_snapshot | backoff | unavailable
    pub status: String,
    pub title: String,
    pub message: String,
    pub sent: u64,
}

impl SyncOutcome {
    fn new(status: &str, title: &str, message: impl Into<String>, sent: u64) -> Self {
        Self { status: status.into(), title: title.into(), message: message.into(), sent }
    }

    /// `kurs:sync --json` çıktısından kullanıcı metni.
    pub fn from_json(v: &serde_json::Value, ok_exit: bool) -> Self {
        let status = v.get("status").and_then(|s| s.as_str()).unwrap_or(if ok_exit { "ok" } else { "error" });
        let text = |k: &str| v.get(k).and_then(|s| s.as_str()).unwrap_or("").trim().to_string();
        let sent = v.pointer("/push/accepted").and_then(|n| n.as_u64()).unwrap_or(0);
        match status {
            "ok" => {
                let msg = if sent > 0 { format!("Eşitlendi · {sent} değişiklik gönderildi") } else { "Eşitlendi · gönderilecek değişiklik yoktu".into() };
                Self::new("ok", "Eşitlendi", msg, sent)
            }
            "running" => Self::new("running", "Eşitleme sürüyor", "Eşitleme zaten sürüyor, birazdan tamamlanır.", 0),
            "offline" => {
                let why = if text("detail").is_empty() { text("message") } else { text("detail") };
                let why = if why.is_empty() { "internet bağlantısı yok ya da sunucu yanıt vermiyor".into() } else { why };
                Self::new("offline", "Sunucuya ulaşılamadı", format!("Sunucuya ulaşılamadı: {why}. Değişiklikler bekletiliyor."), 0)
            }
            "revoked" => Self::new("revoked", "Cihaz erişimi iptal", "Bu cihazın eşitleme erişimi iptal edilmiş. Kurum yöneticinize başvurun.", 0),
            "unpaired" => Self::new("unpaired", "Eşleştirilmedi", "Bu cihaz kurum sunucusuyla eşleştirilmemiş.", 0),
            "needs_snapshot" => Self::new("needs_snapshot", "Kurulum tamamlanmadı", "İlk veri indirmesi tamamlanmamış.", 0),
            "backoff" => Self::new("backoff", "Eşitleme bekliyor", "Eşitleme kısa süre sonra yeniden denenecek.", 0),
            other => {
                let m = text("message");
                Self::new(other, "Eşitleme tamamlanamadı", if m.is_empty() { "Eşitleme tamamlanamadı; ayrıntı günlüklerde.".to_string() } else { format!("Eşitleme tamamlanamadı: {m}") }, 0)
            }
        }
    }
}

/// Eşitleme görevinin denetimi: "hemen tur at" sinyali, bekleyen istekler, bekçi zaman damgası.
#[derive(Default)]
pub struct SyncCtl {
    kick: Notify,
    force: AtomicBool,
    waiters: std::sync::Mutex<Vec<oneshot::Sender<SyncOutcome>>>,
    /// Şu an çalışan `kurs:sync` sürecinin grubu (0 = yok)
    running_pgid: AtomicI32,
    /// Görevin son canlılık damgası (unix sn) — bekçi bununla asılı görevi yeniden kurar
    heartbeat: AtomicU64,
}

impl SyncCtl {
    fn request(&self, force: bool) -> oneshot::Receiver<SyncOutcome> {
        let (tx, rx) = oneshot::channel();
        if let Ok(mut w) = self.waiters.lock() {
            w.push(tx);
        }
        if force {
            self.force.store(true, Ordering::SeqCst);
        }
        self.kick.notify_one();
        rx
    }
    fn deliver(&self, outcome: &SyncOutcome) {
        let waiters: Vec<_> = self.waiters.lock().map(|mut w| w.drain(..).collect()).unwrap_or_default();
        for w in waiters {
            let _ = w.send(outcome.clone());
        }
    }
}

fn unix_now() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0)
}

#[derive(Clone, Serialize)]
pub struct RuntimeStatus {
    pub phase: &'static str,
    pub message: String,
}

pub fn emit_status<R: Runtime>(app: &AppHandle<R>, phase: &'static str, message: impl Into<String>) {
    let _ = app.emit("runtime://status", RuntimeStatus { phase, message: message.into() });
}

#[derive(Clone)]
pub struct RunningInfo {
    pub port: u16,
    pub token: String,
}

impl RunningInfo {
    pub fn origin(&self) -> String {
        format!("http://127.0.0.1:{}", self.port)
    }
    pub fn boot_url(&self, next: &str) -> String {
        let next = if next.starts_with('/') && !next.starts_with("//") { next } else { "/" };
        let mut u = url::Url::parse(&format!("{}/__desktop/boot", self.origin())).expect("geçerli adres");
        u.query_pairs_mut().append_pair("t", &self.token).append_pair("next", next);
        u.to_string()
    }
}

struct Handle {
    info: RunningInfo,
    shutdown: watch::Sender<bool>,
    tasks: Vec<tauri::async_runtime::JoinHandle<()>>,
    /// Zamanlayıcının başlattığı süreç grupları — kapanışta ve uyanmada sonlandırılır
    groups: Groups,
    sync: Arc<SyncCtl>,
    /// `kurs:terminal-dinle` (push dinleyicisi) süreç grubu (0 = çalışmıyor)
    listener_pgid: Arc<AtomicI32>,
}

#[derive(Default)]
pub struct LocalRuntime {
    inner: Mutex<Option<Handle>>,
}

fn free_port() -> Result<u16, String> {
    let l = TcpListener::bind(("127.0.0.1", 0)).map_err(|e| format!("Yerel port ayrılamadı: {e}"))?;
    let port = l.local_addr().map_err(|e| e.to_string())?.port();
    drop(l);
    Ok(port)
}

fn log_file(path: &std::path::Path) -> Stdio {
    match OpenOptions::new().create(true).append(true).open(path) {
        Ok(f) => Stdio::from(f),
        Err(_) => Stdio::null(),
    }
}

#[cfg(unix)]
fn signal_group(pgid: i32, sig: i32) {
    if pgid > 0 {
        // SAFETY: yalnız kendi başlattığımız süreç grubuna sinyal
        unsafe {
            libc::killpg(pgid, sig);
        }
    }
}

/// SQLite dosyasını (WAL dahil) yedekler; en yeni 5 kopya kalır.
pub fn backup_database(paths: &Paths, reason: &str) -> Result<Option<std::path::PathBuf>, String> {
    let db = paths.db_file();
    if !db.is_file() {
        return Ok(None);
    }
    let stamp = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0);
    let dir = paths.backups().join(format!("{stamp}-{reason}"));
    std::fs::create_dir_all(&dir).map_err(|e| format!("Yedek klasörü oluşturulamadı: {e}"))?;
    for suffix in ["", "-wal", "-shm"] {
        let src = std::path::PathBuf::from(format!("{}{}", db.display(), suffix));
        if src.is_file() {
            let name = format!("kurs-local.sqlite{suffix}");
            std::fs::copy(&src, dir.join(name)).map_err(|e| format!("Veritabanı yedeklenemedi: {e}"))?;
        }
    }
    // eski yedekleri buda
    if let Ok(rd) = std::fs::read_dir(paths.backups()) {
        let mut dirs: Vec<_> = rd.flatten().filter(|e| e.path().is_dir()).map(|e| e.path()).collect();
        dirs.sort();
        while dirs.len() > 5 {
            let old = dirs.remove(0);
            let _ = std::fs::remove_dir_all(old);
        }
    }
    log::info!("SQLite yedeği alındı: {}", dir.display());
    Ok(Some(dir))
}

impl LocalRuntime {
    pub async fn running(&self) -> Option<RunningInfo> {
        self.inner.lock().await.as_ref().map(|h| h.info.clone())
    }

    /// Ortamı hazırlar, gerekiyorsa göç ettirir, sunucuyu ve zamanlayıcıyı başlatır. Çalışıyorsa aynısını döner.
    pub async fn start<R: Runtime>(&self, app: &AppHandle<R>, paths: &Paths, previous_version: Option<String>) -> Result<RunningInfo, String> {
        let mut guard = self.inner.lock().await;
        if let Some(h) = guard.as_ref() {
            return Ok(h.info.clone());
        }
        if !paths.runtime_available() {
            return Err("Bu pakette yerel çalışma zamanı (PHP) yok.".into());
        }
        emit_status(app, "starting", "Hazırlanıyor…");
        paths.ensure_local_dirs()?;
        let sec = secrets::load_local()?;
        if sec.device_token.is_none() {
            log::warn!("Cihaz jetonu anahtar zincirinde yok; eşitleme 'eşleşmemiş' görünecek.");
        }

        let version = env!("CARGO_PKG_VERSION").to_string();
        let upgraded = previous_version.as_deref() != Some(version.as_str());
        if upgraded {
            emit_status(app, "migrating", "Yeni sürüm için veritabanı yedekleniyor…");
            backup_database(paths, &format!("v{}", previous_version.clone().unwrap_or_else(|| "ilk".into())))?;
            // eski önbellekleri temizle (paket değişti)
            if let Ok(rd) = std::fs::read_dir(paths.cache_dir()) {
                for e in rd.flatten() {
                    let _ = std::fs::remove_file(e.path());
                }
            }
            let _ = std::fs::remove_dir_all(paths.storage().join("framework/views"));
            paths.ensure_local_dirs()?;
        }

        let base_env = php::build_env(paths, &sec, &[]);
        emit_status(app, "migrating", "Veritabanı denetleniyor…");
        let out = php::artisan(paths, &base_env, &["migrate", "--force", "--no-interaction"], None, Duration::from_secs(600)).await?;
        if !out.ok {
            return Err(format!("Veritabanı güncellenemedi.\n{}", out.tail()));
        }
        if upgraded {
            for args in [["route:cache"], ["event:cache"]] {
                match php::artisan(paths, &base_env, &args, None, Duration::from_secs(120)).await {
                    Ok(o) if o.ok => {}
                    Ok(o) => log::warn!("{} atlandı: {}", args[0], o.tail()),
                    Err(e) => log::warn!("{} atlandı: {e}", args[0]),
                }
            }
        }

        // Ölü süreçlerden (⌘Q, güncelleme, çökme, uyku) kalan zamanlayıcı muteksleri ve tur kilidi: açılışta canlı
        // sahipleri olamaz. Kalırsa ilgili iş 10 dakikaya kadar SESSİZCE atlanır (1.12.0 uyku sonrası olay).
        reset_locks(paths, &base_env).await;

        let token = secrets::new_session_token()?;
        let token_hash = secrets::sha256_hex(&token);
        let (shutdown_tx, shutdown_rx) = watch::channel(false);
        let groups: Groups = Arc::default();
        let sync = Arc::new(SyncCtl::default());
        let listener_pgid = Arc::new(AtomicI32::new(0));

        // Sunucu (port çakışmasına karşı 3 deneme)
        let mut last_err = String::new();
        let mut started: Option<(u16, tokio::process::Child)> = None;
        for _ in 0..3 {
            let port = free_port()?;
            let env = server_env(paths, &sec, port, &token_hash);
            match spawn_server(paths, &env, port) {
                Ok(child) => match wait_healthy(port, &token, child).await {
                    Ok(child) => {
                        started = Some((port, child));
                        break;
                    }
                    Err(e) => last_err = e,
                },
                Err(e) => last_err = e,
            }
        }
        let (port, child) = started.ok_or_else(|| format!("Yerel sunucu başlatılamadı. {last_err}"))?;
        let info = RunningInfo { port, token: token.clone() };
        log::info!("Yerel sunucu hazır: {}", info.origin());

        let tasks = vec![
            tauri::async_runtime::spawn(supervise(
                app.clone(),
                paths.clone(),
                sec_clone(&sec),
                port,
                token.clone(),
                token_hash.clone(),
                child,
                shutdown_rx.clone(),
            )),
            tauri::async_runtime::spawn(watchdog(paths.clone(), sec_clone(&sec), port, shutdown_rx, groups.clone(), sync.clone(), listener_pgid.clone())),
        ];

        *guard = Some(Handle { info: info.clone(), shutdown: shutdown_tx, tasks, groups, sync, listener_pgid });
        emit_status(app, "ready", "Hazır");
        Ok(info)
    }

    /// Süreçleri düzgün durdurur (SIGTERM → 5 sn → SIGKILL). Zamanlayıcının arka plan işleri de sonlandırılır.
    pub async fn stop(&self) {
        let handle = self.inner.lock().await.take();
        let Some(h) = handle else { return };
        log::info!("Yerel sunucu durduruluyor…");
        let _ = h.shutdown.send(true);
        for t in h.tasks {
            let _ = tokio::time::timeout(Duration::from_secs(8), t).await;
        }
        #[cfg(unix)]
        {
            let mut groups: Vec<i32> = h.groups.lock().map(|g| g.iter().map(|(p, _)| *p).collect()).unwrap_or_default();
            let sp = h.sync.running_pgid.load(Ordering::SeqCst);
            if sp > 0 {
                groups.push(sp);
            }
            let lp = h.listener_pgid.swap(0, Ordering::SeqCst);
            if lp > 0 {
                groups.push(lp);
            }
            for pg in &groups {
                signal_group(*pg, libc::SIGTERM);
            }
            if !groups.is_empty() {
                tokio::time::sleep(Duration::from_millis(1500)).await;
                for pg in &groups {
                    signal_group(*pg, libc::SIGKILL);
                }
            }
        }
        log::info!("Yerel sunucu durdu.");
    }

    /// Süreç kapanırken (RunEvent::Exit) eşzamansız bekleyemeyiz: kalan grupları hemen öldür.
    pub fn kill_now(&self) {
        if let Ok(mut guard) = self.inner.try_lock() {
            if let Some(h) = guard.take() {
                let _ = h.shutdown.send(true);
                #[cfg(unix)]
                if let Ok(g) = h.groups.lock() {
                    for (pg, _) in g.iter() {
                        signal_group(*pg, libc::SIGKILL);
                    }
                }
                #[cfg(unix)]
                signal_group(h.sync.running_pgid.load(Ordering::SeqCst), libc::SIGKILL);
                #[cfg(unix)]
                signal_group(h.listener_pgid.swap(0, Ordering::SeqCst), libc::SIGKILL);
            }
        }
    }

    /// "Şimdi eşitle": eşitleme görevine "hemen tur at" sinyali; sonucu (kullanıcı metniyle) döner.
    /// Görev bir tur yürütüyorsa o bitince zorlanmış yeni bir tur atılır. Hiçbir durumda sessiz kalmaz.
    pub async fn sync_now(&self, paths: &Paths) -> SyncOutcome {
        let ctl = self.inner.lock().await.as_ref().map(|h| h.sync.clone());
        let Some(ctl) = ctl else {
            return SyncOutcome::new("unavailable", "Eşitleme kullanılamıyor", "Yerel sunucu çalışmıyor; uygulamayı yeniden açın.", 0);
        };
        let rx = ctl.request(true);
        match tokio::time::timeout(SYNC_TIMEOUT * 2 + Duration::from_secs(10), rx).await {
            Ok(Ok(o)) => o,
            _ => {
                log::warn!("Şimdi eşitle: görev yanıt vermedi ({})", paths.data.display());
                SyncOutcome::new("error", "Eşitleme yanıt vermedi", "Eşitleme görevi zamanında yanıt vermedi; arka planda yeniden kuruluyor. Birazdan yeniden deneyin.", 0)
            }
        }
    }

    /// Gönderilmemiş yerel değişiklik sayısı (sıfırlama öncesi uyarı için).
    pub async fn pending_changes(&self, paths: &Paths) -> Result<u64, String> {
        let sec = secrets::load_local()?;
        let env = php::build_env(paths, &sec, &[]);
        let out = php::artisan(paths, &env, &["kurs:sync", "--status", "--json"], None, Duration::from_secs(60)).await?;
        let v: serde_json::Value = serde_json::from_str(out.stdout.trim()).map_err(|_| format!("Eşitleme durumu okunamadı.\n{}", out.tail()))?;
        Ok(v.get("pending").and_then(|p| p.as_u64()).unwrap_or(0) + v.get("rejected").and_then(|p| p.as_u64()).unwrap_or(0))
    }
}

fn sec_clone(s: &LocalSecrets) -> LocalSecrets {
    LocalSecrets { app_key: s.app_key.clone(), device_token: s.device_token.clone(), data_key: s.data_key.clone() }
}

fn server_env(paths: &Paths, sec: &LocalSecrets, port: u16, token_hash: &str) -> Vec<(String, String)> {
    php::build_env(
        paths,
        sec,
        &[
            ("APP_URL", format!("http://127.0.0.1:{port}")),
            ("ASSET_URL", String::new()),
            ("SESSION_DOMAIN", String::new()),
            ("SESSION_SECURE_COOKIE", "false".into()),
            ("KURS_DESKTOP_TOKEN_HASH", token_hash.to_string()),
            ("PHP_CLI_SERVER_WORKERS", SERVER_WORKERS.into()),
        ],
    )
}

fn spawn_server(paths: &Paths, env: &[(String, String)], port: u16) -> Result<tokio::process::Child, String> {
    let mut cmd = php::base_command(paths, env);
    cmd.arg("-S")
        .arg(format!("127.0.0.1:{port}"))
        .arg("-t")
        .arg(paths.public())
        .arg(paths.router())
        .stdin(Stdio::null())
        .stdout(log_file(&paths.php_server_log()))
        .stderr(log_file(&paths.php_server_log()));
    cmd.spawn().map_err(|e| format!("PHP sunucusu başlatılamadı: {e}"))
}

async fn wait_healthy(port: u16, token: &str, mut child: tokio::process::Child) -> Result<tokio::process::Child, String> {
    let client = reqwest::Client::builder()
        .timeout(Duration::from_secs(5))
        .no_proxy()
        .build()
        .map_err(|e| e.to_string())?;
    let url = format!("http://127.0.0.1:{port}/up");
    let started = Instant::now();
    loop {
        if let Ok(Some(status)) = child.try_wait() {
            return Err(format!("PHP sunucusu hemen kapandı ({status}). Ayrıntı: php-server.log"));
        }
        if let Ok(r) = client.get(&url).header("X-Kurs-Desktop", token).send().await {
            if r.status().is_success() {
                return Ok(child);
            }
            if r.status().as_u16() >= 500 && started.elapsed() > Duration::from_secs(10) {
                let body = r.text().await.unwrap_or_default();
                let _ = child.start_kill();
                return Err(format!("Yerel sunucu hata veriyor. {}", body.chars().take(300).collect::<String>()));
            }
        }
        if started.elapsed() > HEALTH_TIMEOUT {
            let _ = child.start_kill();
            return Err("Yerel sunucu zamanında yanıt vermedi.".into());
        }
        tokio::time::sleep(Duration::from_millis(250)).await;
    }
}

async fn terminate(child: &mut tokio::process::Child) {
    #[cfg(unix)]
    if let Some(pid) = child.id() {
        // process_group(0): grup kimliği = süreç kimliği (php -S işçileri dahil)
        signal_group(pid as i32, libc::SIGTERM);
        if tokio::time::timeout(Duration::from_secs(5), child.wait()).await.is_ok() {
            signal_group(pid as i32, libc::SIGKILL); // artakalan işçiler
            return;
        }
        signal_group(pid as i32, libc::SIGKILL);
    }
    let _ = child.kill().await;
}

#[allow(clippy::too_many_arguments)]
async fn supervise<R: Runtime>(
    app: AppHandle<R>,
    paths: Paths,
    sec: LocalSecrets,
    port: u16,
    token: String,
    token_hash: String,
    mut child: tokio::process::Child,
    mut shutdown: watch::Receiver<bool>,
) {
    let mut restarts: VecDeque<Instant> = VecDeque::new();
    loop {
        tokio::select! {
            _ = shutdown.changed() => {
                terminate(&mut child).await;
                return;
            }
            status = child.wait() => {
                if *shutdown.borrow() {
                    return;
                }
                log::error!("Yerel PHP sunucusu beklenmedik şekilde kapandı: {:?}", status);
                #[cfg(unix)]
                if let Some(pid) = child.id() { signal_group(pid as i32, libc::SIGKILL); }
                let now = Instant::now();
                restarts.push_back(now);
                while restarts.front().is_some_and(|t| now.duration_since(*t) > RESTART_WINDOW) {
                    restarts.pop_front();
                }
                if restarts.len() > MAX_RESTARTS {
                    emit_status(&app, "error", "Yerel sunucu art arda kapandı.");
                    crate::window::show_error(&app, crate::window::ErrorInfo {
                        code: "runtime_crash".into(),
                        title: "Yerel sunucu çalışmıyor".into(),
                        message: "Yerel sunucu 5 dakika içinde birkaç kez kapandı. Günlükleri inceleyip yeniden deneyin; sorun sürerse destek ekibine günlük klasörünü iletin.".into(),
                        detail: Some(format!("Son durum: {status:?}")),
                    });
                    // Handle'ı bırak ki "Yeniden dene" temiz başlatsın
                    let rt = app.state::<crate::AppCtx>();
                    let rt = rt.runtime.clone();
                    tauri::async_runtime::spawn(async move { rt.stop().await; });
                    return;
                }
                emit_status(&app, "restarting", "Yerel sunucu yeniden başlatılıyor…");
                tokio::time::sleep(Duration::from_secs(1 << restarts.len().min(4))).await;
                let env = server_env(&paths, &sec, port, &token_hash);
                match spawn_server(&paths, &env, port) {
                    Ok(c) => match wait_healthy(port, &token, c).await {
                        Ok(c) => {
                            child = c;
                            log::info!("Yerel sunucu yeniden başlatıldı (port {port}).");
                            emit_status(&app, "ready", "Hazır");
                        }
                        Err(e) => {
                            log::error!("Yeniden başlatma başarısız: {e}");
                            // bir sonraki turda child.wait() hemen döner → sayaç artar
                            child = match spawn_placeholder() { Some(c) => c, None => return };
                        }
                    },
                    Err(e) => {
                        log::error!("Yeniden başlatma başarısız: {e}");
                        child = match spawn_placeholder() { Some(c) => c, None => return };
                    }
                }
            }
        }
    }
}

/// Başarısız yeniden başlatmadan sonra döngünün sayacı artırabilmesi için hemen biten zararsız süreç.
fn spawn_placeholder() -> Option<tokio::process::Child> {
    tokio::process::Command::new("/usr/bin/true").spawn().ok()
}

/// Açılış/uyanma temizliği: `kurs:sync --reset-locks` (ölü sahipli tur kilidi + `schedule:clear-cache`).
async fn reset_locks(paths: &Paths, env: &[(String, String)]) {
    match php::artisan(paths, env, &["kurs:sync", "--reset-locks", "--json"], None, Duration::from_secs(60)).await {
        Ok(o) if o.ok => log::info!("Eşitleme kilitleri temizlendi: {}", o.stdout.split_whitespace().collect::<Vec<_>>().join(" ")),
        Ok(o) => log::warn!("Kilit temizliği başarısız: {}", o.tail()),
        Err(e) => log::warn!("Kilit temizliği başarısız: {e}"),
    }
}

/// Zamanlayıcının başlattığı süreç gruplarını sonlandırır (`all`: hepsi — uyanma; değilse yalnız asılı kalanlar).
fn kill_groups(groups: &Groups, all: bool) -> usize {
    let mut n = 0;
    if let Ok(mut g) = groups.lock() {
        let now = Instant::now();
        g.retain(|(pg, t)| {
            let stuck = all || now.duration_since(*t) > STUCK_GROUP_AGE;
            #[cfg(unix)]
            {
                // SAFETY: yalnız kendi başlattığımız gruba 0 sinyali (yaşıyor mu?)
                let alive = *pg > 0 && unsafe { libc::killpg(*pg, 0) } == 0;
                if !alive {
                    return false;
                }
                if stuck {
                    signal_group(*pg, libc::SIGKILL);
                    n += 1;
                    return false;
                }
            }
            #[cfg(not(unix))]
            let _ = (pg, stuck);
            true
        });
    }
    n
}

/// Bekçi: zamanlayıcıyı ve eşitleme görevini ayakta tutar. Biri paniklerse / biterse ya da eşitleme görevi
/// 10 dakikadır canlılık damgası vermezse yeniden kurulur. Uyanma algılanınca temizlik + hemen tur.
async fn watchdog(paths: Paths, sec: LocalSecrets, port: u16, mut shutdown: watch::Receiver<bool>, groups: Groups, sync: Arc<SyncCtl>, listener_pgid: Arc<AtomicI32>) {
    let rx = shutdown.clone();
    let spawn_sched = || tauri::async_runtime::spawn(scheduler(paths.clone(), sec_clone(&sec), port, rx.clone(), groups.clone()));
    let spawn_sync = || tauri::async_runtime::spawn(sync_worker(paths.clone(), sec_clone(&sec), port, rx.clone(), groups.clone(), sync.clone()));
    let spawn_listener = || tauri::async_runtime::spawn(terminal_listener(paths.clone(), sec_clone(&sec), port, rx.clone(), listener_pgid.clone()));
    let mut sched = spawn_sched();
    let mut worker = spawn_sync();
    let mut listener = spawn_listener();
    let mut wall = unix_now();
    let mut mono = Instant::now();
    loop {
        tokio::select! {
            _ = shutdown.changed() => {
                let _ = tokio::time::timeout(Duration::from_secs(8), async { let _ = (&mut sched).await; let _ = (&mut worker).await; let _ = (&mut listener).await; }).await;
                return;
            }
            _ = tokio::time::sleep(Duration::from_secs(10)) => {}
        }
        if *shutdown.borrow() {
            return;
        }
        // Uyku algısı: macOS'ta tokio/Instant uykuda ilerlemez, duvar saati ilerler
        let (w, m) = (unix_now(), Instant::now());
        let gap = w.saturating_sub(wall).saturating_sub(m.duration_since(mono).as_secs());
        wall = w;
        mono = m;
        if gap > WAKE_GAP_SECS {
            log::warn!("Uyanma algılandı (~{gap} sn askıda): asılı PHP süreçleri sonlandırılıyor, eşitleme hemen deneniyor");
            let killed = kill_groups(&groups, true);
            #[cfg(unix)]
            {
                let sp = sync.running_pgid.swap(0, Ordering::SeqCst);
                if sp > 0 {
                    signal_group(sp, libc::SIGKILL);
                }
            }
            log::info!("Uyanma: {killed} zamanlayıcı grubu sonlandırıldı");
            let env = php::build_env(&paths, &sec, &[]);
            reset_locks(&paths, &env).await;
            sync.force.store(true, Ordering::SeqCst);
            sync.heartbeat.store(unix_now(), Ordering::SeqCst);
            sync.kick.notify_one();
        } else {
            kill_groups(&groups, false);
        }
        if sched.inner().is_finished() {
            log::error!("Zamanlayıcı görevi durmuş; yeniden kuruluyor");
            sched = spawn_sched();
        }
        if listener.inner().is_finished() {
            log::error!("Push dinleyicisi görevi durmuş; yeniden kuruluyor");
            #[cfg(unix)]
            signal_group(listener_pgid.swap(0, Ordering::SeqCst), libc::SIGKILL);
            listener = spawn_listener();
        }
        let hb = sync.heartbeat.load(Ordering::SeqCst);
        let hung = hb > 0 && unix_now().saturating_sub(hb) > 600;
        if worker.inner().is_finished() || hung {
            log::error!("Eşitleme görevi {}; yeniden kuruluyor", if hung { "10 dakikadır yanıt vermiyor" } else { "durmuş" });
            worker.abort();
            #[cfg(unix)]
            signal_group(sync.running_pgid.swap(0, Ordering::SeqCst), libc::SIGKILL);
            sync.heartbeat.store(unix_now(), Ordering::SeqCst);
            worker = spawn_sync();
            sync.kick.notify_one();
        }
    }
}

/// Eşitleme görevi: 30 sn'de bir `kurs:sync` TEK TUR (Laravel zamanlayıcısının muteksine bağlı değil).
/// Biri sürerken yenisi başlamaz; tur 150 sn'yi aşarsa süreç grubu öldürülür. "Şimdi eşitle" sinyaliyle hemen.
async fn sync_worker(paths: Paths, sec: LocalSecrets, port: u16, mut shutdown: watch::Receiver<bool>, _groups: Groups, ctl: Arc<SyncCtl>) {
    let env = php::build_env(&paths, &sec, &[("APP_URL", format!("http://127.0.0.1:{port}"))]);
    let mut wait = Duration::from_secs(4);
    loop {
        ctl.heartbeat.store(unix_now(), Ordering::SeqCst);
        tokio::select! {
            _ = shutdown.changed() => return,
            _ = tokio::time::sleep(wait) => {}
            _ = ctl.kick.notified() => {}
        }
        if *shutdown.borrow() {
            return;
        }
        ctl.heartbeat.store(unix_now(), Ordering::SeqCst);
        let force = ctl.force.swap(false, Ordering::SeqCst);
        let outcome = run_sync_once(&paths, &env, force, &ctl).await;
        if force || outcome.status != "backoff" {
            log::info!("Eşitleme turu{}: {} — {}", if force { " (zorla)" } else { "" }, outcome.status, outcome.message);
        }
        ctl.deliver(&outcome);
        wait = SYNC_INTERVAL;
    }
}

async fn run_sync_once(paths: &Paths, env: &[(String, String)], force: bool, ctl: &SyncCtl) -> SyncOutcome {
    let mut cmd = php::base_command(paths, env);
    cmd.arg(paths.artisan()).args(["kurs:sync", "--json", "--no-ansi"]);
    if force {
        cmd.arg("--force");
    }
    cmd.stdin(Stdio::null()).stdout(Stdio::piped()).stderr(log_file(&paths.scheduler_log()));
    let child = match cmd.spawn() {
        Ok(c) => c,
        Err(e) => return SyncOutcome::new("error", "Eşitleme başlatılamadı", format!("PHP başlatılamadı: {e}"), 0),
    };
    let pgid = child.id().map(|p| p as i32).unwrap_or(0);
    ctl.running_pgid.store(pgid, Ordering::SeqCst);
    let res = tokio::time::timeout(SYNC_TIMEOUT, child.wait_with_output()).await;
    ctl.running_pgid.store(0, Ordering::SeqCst);
    match res {
        Ok(Ok(out)) => {
            let text = String::from_utf8_lossy(&out.stdout);
            match serde_json::from_str::<serde_json::Value>(text.trim()) {
                Ok(v) => SyncOutcome::from_json(&v, out.status.success()),
                Err(_) => SyncOutcome::new("error", "Eşitleme tamamlanamadı", "Eşitleme komutu beklenmedik çıktı verdi; ayrıntı günlüklerde.", 0),
            }
        }
        Ok(Err(e)) => SyncOutcome::new("error", "Eşitleme tamamlanamadı", format!("PHP süreci okunamadı: {e}"), 0),
        Err(_) => {
            #[cfg(unix)]
            signal_group(pgid, libc::SIGKILL);
            log::warn!("kurs:sync {} sn'de bitmedi; süreç grubu sonlandırıldı", SYNC_TIMEOUT.as_secs());
            SyncOutcome::new("offline", "Sunucuya ulaşılamadı", "Sunucuya ulaşılamadı: bağlantı zaman aşımına uğradı. Değişiklikler bekletiliyor.", 0)
        }
    }
}

/// Push dinleyicisi (`kurs:terminal-dinle`): yoklama terminalinin kendisinin bağlanıp veri gönderdiği TCP sunucusu
/// (varsayılan 0.0.0.0:7005). Denetlenen uzun ömürlü süreç: ayar kapalıysa komut 0 ile çıkar ve 20 sn sonra yeniden
/// denenir; port değişince 3 ile çıkar ve hemen yeniden başlar; çökme/port açılamazsa artan bekleme (en çok 60 sn).
/// Kapanışta SIGTERM (komut bağlantıları kaydedip düzgün kapanır) → 5 sn → SIGKILL.
/// macOS: ilk dinlemede Uygulama Güvenlik Duvarı "gelen bağlantılara izin ver" diye sorabilir (Info.plist'teki Yerel Ağ
/// izni bağlantı KURMAK içindir; gelen bağlantı için güvenlik duvarı ayrı sorar).
async fn terminal_listener(paths: Paths, sec: LocalSecrets, port: u16, mut shutdown: watch::Receiver<bool>, pgid: Arc<AtomicI32>) {
    let env = php::build_env(&paths, &sec, &[("APP_URL", format!("http://127.0.0.1:{port}"))]);
    let mut backoff = Duration::from_secs(2);
    // İlk tur: sunucu ve göçler hazır olduktan kısa süre sonra
    let mut wait = Duration::from_secs(5);
    loop {
        tokio::select! {
            _ = shutdown.changed() => return,
            _ = tokio::time::sleep(wait) => {}
        }
        if *shutdown.borrow() {
            return;
        }
        let mut cmd = php::base_command(&paths, &env);
        cmd.arg(paths.artisan())
            .args(["kurs:terminal-dinle", "--no-ansi", "--no-interaction"])
            .stdin(Stdio::null())
            .stdout(log_file(&paths.scheduler_log()))
            .stderr(log_file(&paths.scheduler_log()))
            .kill_on_drop(true);
        let mut child = match cmd.spawn() {
            Ok(c) => c,
            Err(e) => {
                log::error!("Push dinleyicisi başlatılamadı: {e}");
                write_listener_status(&paths, "baslatilamadi", 0, None, Some(format!("PHP başlatılamadı: {e}")), backoff.as_secs());
                wait = backoff;
                backoff = (backoff * 2).min(LISTENER_MAX_BACKOFF);
                continue;
            }
        };
        let group = child.id().map(|p| p as i32).unwrap_or(0);
        pgid.store(group, Ordering::SeqCst);
        write_listener_status(&paths, "calisiyor", group, None, None, 0);
        let started = Instant::now();
        let status = tokio::select! {
            _ = shutdown.changed() => {
                #[cfg(unix)]
                signal_group(group, libc::SIGTERM);
                if tokio::time::timeout(Duration::from_secs(5), child.wait()).await.is_err() {
                    #[cfg(unix)]
                    signal_group(group, libc::SIGKILL);
                    let _ = child.kill().await;
                }
                pgid.store(0, Ordering::SeqCst);
                write_listener_status(&paths, "durduruldu", 0, None, None, 0);
                return;
            }
            s = child.wait() => s,
        };
        pgid.store(0, Ordering::SeqCst);
        #[cfg(unix)]
        signal_group(group, libc::SIGKILL); // artakalan olursa
        let code = status.as_ref().ok().and_then(|s| s.code());
        match code {
            Some(0) => {
                // push kapalı ya da düzgün durdu: ayar açılınca birkaç saniye içinde başlasın
                wait = LISTENER_IDLE_RECHECK;
                backoff = Duration::from_secs(2);
                write_listener_status(&paths, "bekliyor", 0, code, None, wait.as_secs());
            }
            Some(3) => {
                log::info!("Push dinleyicisi ayarı değişti; yeniden başlatılıyor");
                wait = Duration::from_millis(500);
                backoff = Duration::from_secs(2);
                write_listener_status(&paths, "yeniden_baslatiliyor", 0, code, None, 0);
            }
            other => {
                if started.elapsed() > Duration::from_secs(120) {
                    backoff = Duration::from_secs(2);
                }
                log::warn!("Push dinleyicisi kapandı (çıkış {other:?}); {} sn sonra yeniden denenecek", backoff.as_secs());
                write_listener_status(&paths, "cikti", 0, other, Some(format!("Dinleyici süreci çıkış kodu {other:?} ile kapandı; ayrıntı scheduler.log / terminal log.")), backoff.as_secs());
                wait = backoff;
                backoff = (backoff * 2).min(LISTENER_MAX_BACKOFF);
            }
        }
    }
}

/// Denetçi durumunu uygulamaya bildirir (storage/app/terminal/listener-supervisor.json → Terminal Teşhis ekranı).
fn write_listener_status(paths: &Paths, state: &str, pid: i32, exit_code: Option<i32>, error: Option<String>, next_try_secs: u64) {
    let dir = paths.storage().join("app/terminal");
    let _ = std::fs::create_dir_all(&dir);
    let body = serde_json::json!({
        "durum": state,
        "pid": if pid > 0 { Some(pid) } else { None },
        "son_cikis_kodu": exit_code,
        "son_hata": error,
        "sonraki_deneme_sn": next_try_secs,
        "zaman": unix_now(),
        "surum": env!("CARGO_PKG_VERSION"),
    });
    let tmp = dir.join("listener-supervisor.json.tmp");
    if std::fs::write(&tmp, body.to_string()).is_ok() {
        let _ = std::fs::rename(&tmp, dir.join("listener-supervisor.json"));
    }
}

/// Dakikada bir `artisan schedule:run` (yerel düğümde eşitleme dışındaki yerel işler, ör. cihaz çekme).
async fn scheduler(paths: Paths, sec: LocalSecrets, port: u16, mut shutdown: watch::Receiver<bool>, groups: Groups) {
    let env = php::build_env(&paths, &sec, &[("APP_URL", format!("http://127.0.0.1:{port}"))]);
    // İlk tur 3 sn sonra (açılışta hemen eşitlesin), sonra dakika başlarında
    let mut wait = Duration::from_secs(3);
    loop {
        tokio::select! {
            _ = shutdown.changed() => return,
            _ = tokio::time::sleep(wait) => {}
        }
        let mut cmd = php::base_command(&paths, &env);
        cmd.arg(paths.artisan())
            .args(["schedule:run", "--no-ansi", "--no-interaction"])
            .stdin(Stdio::null())
            .stdout(log_file(&paths.scheduler_log()))
            .stderr(log_file(&paths.scheduler_log()))
            .kill_on_drop(false);
        match cmd.spawn() {
            Ok(mut child) => {
                if let Some(pid) = child.id() {
                    if let Ok(mut g) = groups.lock() {
                        // Gruplar bitene dek listede kalır (bekçi ölüleri ayıklar, asılı kalanları sonlandırır)
                        g.push_back((pid as i32, Instant::now()));
                        while g.len() > 64 {
                            g.pop_front();
                        }
                    }
                }
                tokio::select! {
                    _ = shutdown.changed() => { let _ = child.start_kill(); return; }
                    r = tokio::time::timeout(Duration::from_secs(300), child.wait()) => {
                        if r.is_err() {
                            log::warn!("schedule:run 5 dakikada bitmedi; sonlandırılıyor");
                            let _ = child.start_kill();
                        }
                    }
                }
            }
            Err(e) => log::error!("Zamanlayıcı başlatılamadı: {e}"),
        }
        let secs = SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0);
        wait = Duration::from_secs(60 - (secs % 60)).max(Duration::from_secs(5));
    }
}
