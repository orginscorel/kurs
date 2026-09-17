//! Yerel kip (A) ilk kurulum akışı:
//!   hazırlık (.env + APP_KEY) → migrate → kurs:desktop-pair (sırlar anahtar zincirine) → kurs:desktop-snapshot → başlat
//! İlerleme `setup://progress` olayıyla kurulum ön yüzüne gider.

use crate::settings::Mode;
use crate::{php, runtime, secrets, window, AppCtx};
use serde::{Deserialize, Serialize};
use std::time::Duration;
use tauri::{AppHandle, Emitter, Manager, Runtime};
use tauri_plugin_autostart::ManagerExt as AutostartExt;

#[derive(Debug, Deserialize)]
pub struct SetupInput {
    pub server_url: String,
    pub code: String,
    pub login: String,
    pub password: String,
    pub device_name: String,
}

#[derive(Clone, Serialize)]
struct Progress {
    step: &'static str,
    status: &'static str,
    percent: Option<f64>,
    detail: Option<String>,
}

fn emit<R: Runtime>(app: &AppHandle<R>, step: &'static str, status: &'static str, percent: Option<f64>, detail: Option<String>) {
    let _ = app.emit("setup://progress", Progress { step, status, percent, detail });
}

/// Yarım kalmış yerel kurulumu temizler (veritabanı, storage, önbellek, .env, anahtarlar).
pub fn wipe_local_files(ctx: &AppCtx) -> Result<(), String> {
    let root = ctx.paths.local_root();
    if root.exists() {
        std::fs::remove_dir_all(&root).map_err(|e| format!("Yerel veri silinemedi: {e}"))?;
    }
    secrets::wipe_local()
}

pub async fn setup_local<R: Runtime>(app: &AppHandle<R>, input: SetupInput) -> Result<(), String> {
    let ctx = app.state::<AppCtx>();
    let current = ctx.settings_snapshot();
    if current.setup.snapshot_done {
        return Err("Bu bilgisayarda yerel kurulum zaten tamamlanmış. Yeniden kurmak için önce menüden kurulumu sıfırlayın.".into());
    }
    if !ctx.paths.runtime_available() {
        return Err("Bu pakette yerel çalışma zamanı yok; yalnız çevrimiçi kullanım mümkün.".into());
    }
    let server = input.server_url.trim().trim_end_matches('/').to_string();

    // 1) Hazırlık
    emit(app, "prepare", "running", None, None);
    ctx.runtime.stop().await;
    wipe_local_files(&ctx)?;
    ctx.paths.ensure_local_dirs()?;
    window::ping_server(&server).await.map_err(|e| format!("Sunucu denetimi: {e}"))?;
    secrets::ensure_app_key()?;
    php::write_env_file(&ctx.paths, &server)?;
    ctx.update_settings(|s| {
        s.mode = Mode::Local;
        s.server_url = Some(server.clone());
        s.device_name = Some(input.device_name.clone());
        s.setup.env_ready = true;
        s.setup.paired = false;
        s.setup.snapshot_done = false;
        s.last_bundle_version = None;
    })?;
    emit(app, "prepare", "done", None, None);

    // 2) Veritabanı
    emit(app, "migrate", "running", None, None);
    let sec = secrets::load_local()?;
    let env = php::build_env(&ctx.paths, &sec, &[]);
    let out = php::artisan(&ctx.paths, &env, &["migrate", "--force", "--no-interaction"], None, Duration::from_secs(900)).await?;
    if !out.ok {
        emit(app, "migrate", "error", None, Some(out.tail()));
        return Err(format!("Yerel veritabanı oluşturulamadı.\n{}", out.tail()));
    }
    emit(app, "migrate", "done", None, None);

    // 3) Eşleştirme — parola STDIN ile, komut satırında görünmez
    emit(app, "pair", "running", None, None);
    let payload = serde_json::json!({
        "server": server,
        "code": input.code,
        "login": input.login,
        "password": input.password,
        "name": input.device_name,
        "platform": if cfg!(target_os = "macos") { "macos" } else if cfg!(windows) { "windows" } else { "linux" },
    })
    .to_string();
    let mut error: Option<String> = None;
    let mut token: Option<String> = None;
    let mut data_key: Option<String> = None;
    let mut device_code: Option<String> = None;
    let mut key_info: Option<String> = None;
    let out = php::artisan_events(&ctx.paths, &env, &["kurs:desktop-pair"], Some(payload), Duration::from_secs(120), |ev| {
        match ev.get("event").and_then(|e| e.as_str()) {
            Some("paired") => {
                device_code = ev.pointer("/device/code").and_then(|c| c.as_str()).map(str::to_string);
                key_info = ev.get("key").and_then(|k| k.as_str()).map(str::to_string);
            }
            Some("secrets") => {
                token = ev.get("device_token").and_then(|t| t.as_str()).map(str::to_string);
                data_key = ev.get("data_key").and_then(|t| t.as_str()).filter(|s| !s.is_empty()).map(str::to_string);
            }
            Some("error") => error = ev.get("message").and_then(|m| m.as_str()).map(str::to_string),
            _ => {}
        }
    })
    .await?;
    if let Some(e) = error {
        emit(app, "pair", "error", None, Some(e.clone()));
        return Err(e);
    }
    let token = match token {
        Some(t) if out.ok => t,
        _ => {
            let msg = format!("Eşleştirme tamamlanamadı.\n{}", out.tail());
            emit(app, "pair", "error", None, Some(msg.clone()));
            return Err(msg);
        }
    };
    secrets::set(secrets::DEVICE_TOKEN, &token)?;
    match &data_key {
        Some(k) => secrets::set(secrets::DATA_KEY, k)?,
        None => secrets::delete(secrets::DATA_KEY)?,
    }
    ctx.update_settings(|s| {
        s.setup.paired = true;
        s.device_code = device_code.clone();
    })?;
    let detail = match (&device_code, &key_info) {
        (Some(c), Some(k)) => Some(format!("Cihaz kodu {c} · kurum veri anahtarı: {k}")),
        (Some(c), None) => Some(format!("Cihaz kodu {c}")),
        _ => None,
    };
    emit(app, "pair", "done", None, detail);

    snapshot_and_start(app).await
}

/// Eşleşmiş ama ilk eşitlemesi bitmemiş kurulumu sürdürür.
pub async fn resume<R: Runtime>(app: &AppHandle<R>) -> Result<(), String> {
    let ctx = app.state::<AppCtx>();
    let s = ctx.settings_snapshot();
    if !(s.mode == Mode::Local && s.setup.paired) {
        return Err("Sürdürülecek bir kurulum yok. Kurulumu baştan başlatın.".into());
    }
    if s.setup.snapshot_done {
        return start_and_open(app).await;
    }
    snapshot_and_start(app).await
}

async fn snapshot_and_start<R: Runtime>(app: &AppHandle<R>) -> Result<(), String> {
    let ctx = app.state::<AppCtx>();
    emit(app, "snapshot", "running", Some(0.0), Some("Sunucudaki kayıtlar sayılıyor…".into()));
    let sec = secrets::load_local()?;
    if sec.device_token.is_none() {
        return Err("Cihaz jetonu anahtar zincirinde yok. Kurulumu baştan başlatın.".into());
    }
    let env = php::build_env(&ctx.paths, &sec, &[]);
    let mut error: Option<String> = None;
    let mut finished = false;
    let progress_app = app.clone();
    let out = php::artisan_events(&ctx.paths, &env, &["kurs:desktop-snapshot"], None, Duration::from_secs(3 * 60 * 60), |ev| {
        match ev.get("event").and_then(|e| e.as_str()) {
            Some("start") => {
                let rows = ev.get("rows").and_then(|r| r.as_u64()).unwrap_or(0);
                emit(&progress_app, "snapshot", "running", Some(1.0), Some(format!("{rows} kayıt indirilecek")));
            }
            Some("progress") => {
                let pct = ev.get("percent").and_then(|p| p.as_f64());
                let done = ev.get("done").and_then(|p| p.as_u64()).unwrap_or(0);
                let total = ev.get("total").and_then(|p| p.as_u64()).unwrap_or(0);
                let table = ev.get("table").and_then(|t| t.as_str()).unwrap_or("");
                emit(&progress_app, "snapshot", "running", pct, Some(format!("{done} / {total} kayıt · {table}")));
            }
            Some("done") => finished = true,
            Some("error") => error = ev.get("message").and_then(|m| m.as_str()).map(str::to_string),
            _ => {}
        }
    })
    .await?;
    if let Some(e) = error {
        emit(app, "snapshot", "error", None, Some(e.clone()));
        return Err(format!("{e}\nİnternet bağlantısını denetleyip \"Yeniden dene\" ile kaldığı yerden sürdürebilirsiniz."));
    }
    if !finished || !out.ok {
        let msg = format!("İlk eşitleme tamamlanamadı.\n{}", out.tail());
        emit(app, "snapshot", "error", None, Some(msg.clone()));
        return Err(msg);
    }
    ctx.update_settings(|s| s.setup.snapshot_done = true)?;
    emit(app, "snapshot", "done", Some(100.0), None);

    emit(app, "start", "running", None, None);
    if let Err(e) = start_and_open(app).await {
        emit(app, "start", "error", None, Some(e.clone()));
        return Err(e);
    }
    emit(app, "start", "done", None, None);
    // Yerel kurulumda eşitleme arka planda sürsün: oturum açılınca başlat (menüden kapatılabilir)
    if let Err(e) = app.autolaunch().enable() {
        log::warn!("Otomatik başlatma açılamadı: {e}");
    }
    Ok(())
}

/// Yerel sunucuyu başlatır ve pencereyi yerel adrese yönlendirir.
pub async fn start_and_open<R: Runtime>(app: &AppHandle<R>) -> Result<(), String> {
    let ctx = app.state::<AppCtx>();
    let settings = ctx.settings_snapshot();
    if !settings.local_ready() {
        return Err("Yerel kurulum tamamlanmamış.".into());
    }
    let info = ctx.runtime.start(app, &ctx.paths, settings.last_bundle_version.clone()).await?;
    let version = env!("CARGO_PKG_VERSION").to_string();
    if settings.last_bundle_version.as_deref() != Some(version.as_str()) {
        ctx.update_settings(|s| s.last_bundle_version = Some(version.clone()))?;
    }
    window::allow_origin(app, &info.origin());
    let next = ctx.take_pending_path().unwrap_or_else(|| "/".into());
    let url = url::Url::parse(&info.boot_url(&next)).map_err(|e| e.to_string())?;
    let w = window::main_window(app).ok_or("Ana pencere yok.")?;
    w.navigate(url).map_err(|e| e.to_string())?;
    runtime::emit_status(app, "ready", "Hazır");
    Ok(())
}
