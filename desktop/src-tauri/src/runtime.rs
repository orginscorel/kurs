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
use std::sync::Arc;
use std::time::{Duration, Instant};
use tauri::{AppHandle, Emitter, Manager, Runtime};
use tokio::sync::{watch, Mutex};

const SERVER_WORKERS: &str = "4";
const HEALTH_TIMEOUT: Duration = Duration::from_secs(40);
const MAX_RESTARTS: usize = 5;
const RESTART_WINDOW: Duration = Duration::from_secs(300);

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
    /// Zamanlayıcının başlattığı süreç grupları (arka plandaki `kurs:sync` dahil) — kapanışta sonlandırılır
    groups: Arc<std::sync::Mutex<VecDeque<(i32, Instant)>>>,
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

        let token = secrets::new_session_token()?;
        let token_hash = secrets::sha256_hex(&token);
        let (shutdown_tx, shutdown_rx) = watch::channel(false);
        let groups: Arc<std::sync::Mutex<VecDeque<(i32, Instant)>>> = Arc::default();

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
            tauri::async_runtime::spawn(scheduler(paths.clone(), sec_clone(&sec), port, shutdown_rx, groups.clone())),
        ];

        *guard = Some(Handle { info: info.clone(), shutdown: shutdown_tx, tasks, groups });
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
            let groups: Vec<i32> = h.groups.lock().map(|g| g.iter().map(|(p, _)| *p).collect()).unwrap_or_default();
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
            }
        }
    }

    /// "Şimdi eşitle": zamanlayıcıyı beklemeden tek tur.
    pub async fn sync_now(&self, paths: &Paths) -> Result<String, String> {
        let sec = secrets::load_local()?;
        let env = php::build_env(paths, &sec, &[]);
        let out = php::artisan(paths, &env, &["kurs:sync", "--force", "--json"], None, Duration::from_secs(300)).await?;
        let status = serde_json::from_str::<serde_json::Value>(&out.stdout)
            .ok()
            .and_then(|v| v.get("status").and_then(|s| s.as_str()).map(str::to_string))
            .unwrap_or_else(|| if out.ok { "ok".into() } else { "error".into() });
        Ok(status)
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

/// Dakikada bir `artisan schedule:run` (yerelde yalnız eşitleme zamanlayıcısı yüklüdür).
async fn scheduler(
    paths: Paths,
    sec: LocalSecrets,
    port: u16,
    mut shutdown: watch::Receiver<bool>,
    groups: Arc<std::sync::Mutex<VecDeque<(i32, Instant)>>>,
) {
    use std::time::{SystemTime, UNIX_EPOCH};
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
                        let now = Instant::now();
                        g.push_back((pid as i32, now));
                        // 3 dk'dan eski gruplar (arka plan kurs:sync --loop=55 bitmiştir)
                        while g.front().is_some_and(|(_, t)| now.duration_since(*t) > Duration::from_secs(180)) {
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
