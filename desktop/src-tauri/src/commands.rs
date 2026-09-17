//! Ön yüzden çağrılan komutlar. İzinler: capabilities/*.json (build.rs'deki liste).

use crate::settings::{Mode, Settings};
use crate::setup_flow::{self, SetupInput};
use crate::{runtime, updater, window, AppCtx};
use serde::Serialize;
use std::sync::atomic::Ordering;
use tauri::{AppHandle, Manager, Runtime, WebviewWindow};
use tauri_plugin_autostart::ManagerExt as AutostartExt;
use tauri_plugin_notification::NotificationExt;
use tauri_plugin_opener::OpenerExt;

#[derive(Serialize)]
pub struct SetupFlags {
    env_ready: bool,
    paired: bool,
    snapshot_done: bool,
}

#[derive(Serialize)]
pub struct AppState {
    version: String,
    mode: Mode,
    server_url: Option<String>,
    default_server: String,
    device_name: String,
    platform: String,
    arch: String,
    runtime_available: bool,
    setup: SetupFlags,
    last_error: Option<window::ErrorInfo>,
}

fn host_name() -> String {
    // macOS: "Ayşe'nin MacBook Air'i" gibi; yoksa genel ad
    std::process::Command::new("/usr/sbin/scutil")
        .args(["--get", "ComputerName"])
        .output()
        .ok()
        .filter(|o| o.status.success())
        .map(|o| String::from_utf8_lossy(&o.stdout).trim().to_string())
        .filter(|s| !s.is_empty())
        .unwrap_or_else(|| "Kurum bilgisayarı".into())
}

#[tauri::command]
pub fn app_state<R: Runtime>(app: AppHandle<R>) -> AppState {
    let ctx = app.state::<AppCtx>();
    let s: Settings = ctx.settings_snapshot();
    AppState {
        version: env!("CARGO_PKG_VERSION").into(),
        mode: s.mode.clone(),
        server_url: s.server_url.clone(),
        default_server: crate::DEFAULT_SERVER.into(),
        device_name: s.device_name.clone().unwrap_or_else(host_name),
        platform: std::env::consts::OS.into(),
        arch: std::env::consts::ARCH.into(),
        runtime_available: ctx.paths.runtime_available(),
        setup: SetupFlags { env_ready: s.setup.env_ready, paired: s.setup.paired, snapshot_done: s.setup.snapshot_done },
        last_error: ctx.last_error.read().ok().and_then(|e| e.clone()),
    }
}

#[derive(Serialize)]
pub struct ServerInfo {
    ok: bool,
    node: Option<String>,
    protocol: Option<u64>,
    message: String,
}

#[tauri::command]
pub async fn check_server(server_url: String) -> Result<ServerInfo, String> {
    match window::ping_server(&server_url).await {
        Ok(v) => {
            let node = v.get("node").and_then(|n| n.as_str()).map(str::to_string);
            if node.as_deref() == Some("local") {
                return Ok(ServerInfo { ok: false, node, protocol: None, message: "Bu adres bir yerel kurulum; kurum web sunucusunun adresini yazın.".into() });
            }
            Ok(ServerInfo {
                ok: true,
                node,
                protocol: v.get("protocol").and_then(|p| p.as_u64()),
                message: "Sunucu yanıt verdi.".into(),
            })
        }
        Err(e) => Ok(ServerInfo { ok: false, node: None, protocol: None, message: e }),
    }
}

#[tauri::command]
pub async fn choose_remote<R: Runtime>(app: AppHandle<R>, server_url: String) -> Result<(), String> {
    let ctx = app.state::<AppCtx>();
    let current = ctx.settings_snapshot();
    if current.mode == Mode::Local && current.setup.paired {
        return Err("Bu bilgisayarda yerel kurulum var. Çevrimiçi kullanıma geçmek için önce kurulumu sıfırlayın.".into());
    }
    let url = server_url.trim().trim_end_matches('/').to_string();
    match window::open_remote(&app, &url, None).await {
        Ok(()) => {
            ctx.update_settings(|s| {
                s.mode = Mode::Remote;
                s.server_url = Some(url.clone());
            })?;
            if let Ok(mut e) = ctx.last_error.write() {
                *e = None;
            }
            Ok(())
        }
        Err(e) => Err(e),
    }
}

#[tauri::command]
pub async fn setup_local<R: Runtime>(app: AppHandle<R>, input: SetupInput) -> Result<(), String> {
    let ctx = app.state::<AppCtx>();
    let _busy = ctx.setup_lock.try_lock().map_err(|_| "Kurulum zaten sürüyor.".to_string())?;
    setup_flow::setup_local(&app, input).await
}

#[tauri::command]
pub async fn resume_setup<R: Runtime>(app: AppHandle<R>) -> Result<(), String> {
    let ctx = app.state::<AppCtx>();
    let _busy = ctx.setup_lock.try_lock().map_err(|_| "Kurulum zaten sürüyor.".to_string())?;
    setup_flow::resume(&app).await
}

#[tauri::command]
pub async fn start_local<R: Runtime>(app: AppHandle<R>) -> Result<(), String> {
    let result = setup_flow::start_and_open(&app).await;
    if let Err(e) = &result {
        runtime::emit_status(&app, "error", e.clone());
    }
    result
}

#[derive(Serialize)]
pub struct ResetResult {
    done: bool,
    pending: u64,
    message: String,
}

/// Kurulumu sıfırlar. Yerel kipte gönderilmemiş değişiklik varsa `force` olmadan durur; zorlanırsa önce SQLite yedeklenir.
#[tauri::command]
pub async fn reset_setup<R: Runtime>(app: AppHandle<R>, force: bool) -> Result<ResetResult, String> {
    let ctx = app.state::<AppCtx>();
    let s = ctx.settings_snapshot();
    if s.mode == Mode::Local && s.setup.env_ready {
        if s.setup.snapshot_done && !force {
            match ctx.runtime.pending_changes(&ctx.paths).await {
                Ok(0) => {}
                Ok(n) => {
                    return Ok(ResetResult {
                        done: false,
                        pending: n,
                        message: format!("Sunucuya gönderilmemiş ya da reddedilmiş {n} değişiklik var. İnternete bağlanıp eşitlemeyi bekleyin; yine de sıfırlarsanız bu değişiklikler web'e gitmez (veritabanının bir kopyası yedek klasörüne alınır)."),
                    })
                }
                Err(e) => {
                    return Ok(ResetResult { done: false, pending: 0, message: format!("Bekleyen değişiklikler denetlenemedi: {e}") });
                }
            }
        }
        ctx.runtime.stop().await;
        if let Err(e) = runtime::backup_database(&ctx.paths, "sifirlama") {
            log::warn!("Sıfırlama öncesi yedek alınamadı: {e}");
        }
        setup_flow::wipe_local_files(&ctx)?;
        if let Err(e) = app.autolaunch().disable() {
            log::warn!("Otomatik başlatma kapatılamadı: {e}");
        }
    }
    ctx.update_settings(|st| {
        let keep_name = st.device_name.clone();
        *st = Settings { device_name: keep_name, ..Settings::default() };
    })?;
    if let Ok(mut e) = ctx.last_error.write() {
        *e = None;
    }
    Ok(ResetResult {
        done: true,
        pending: 0,
        message: "Kurulum sıfırlandı. Cihaz web panelinde \"Bağlı cihazlar\" listesinde kalır; gerekirse oradan iptal edin.".into(),
    })
}

pub fn open_logs_dir<R: Runtime>(app: &AppHandle<R>) {
    let dir = app.state::<AppCtx>().paths.logs.clone();
    let _ = std::fs::create_dir_all(&dir);
    if let Err(e) = app.opener().open_path(dir.to_string_lossy(), None::<&str>) {
        log::warn!("Günlük klasörü açılamadı: {e}");
    }
}

#[tauri::command]
pub fn open_logs<R: Runtime>(app: AppHandle<R>) {
    open_logs_dir(&app);
}

/// Düzgün çıkış: yerel sunucuyu durdur, sonra kapat.
pub fn request_quit<R: Runtime>(app: AppHandle<R>) {
    let ctx = app.state::<AppCtx>();
    if ctx.quitting.swap(true, Ordering::SeqCst) {
        return;
    }
    tauri::async_runtime::spawn(async move {
        let ctx = app.state::<AppCtx>();
        ctx.runtime.stop().await;
        app.exit(0);
    });
}

#[tauri::command]
pub fn quit_app<R: Runtime>(app: AppHandle<R>) {
    request_quit(app);
}

/// Şerit (bridge.js) ve güncelleme penceresi bunu çağırır. Ana pencereden gelen çağrı aynı zamanda
/// "şerit çalışıyor" işaretidir: gelmezse updater ayrı pencereyi açar (src/updater.rs › present).
#[tauri::command]
pub async fn update_info<R: Runtime>(app: AppHandle<R>, window: WebviewWindow<R>) -> Option<updater::UpdateInfo> {
    if window.label() == window::MAIN {
        app.state::<AppCtx>().banner_seen.fetch_add(1, Ordering::SeqCst);
    }
    updater::info(&app).await
}

#[tauri::command]
pub async fn update_install<R: Runtime>(app: AppHandle<R>) -> Result<(), String> {
    updater::install(&app).await
}

#[tauri::command]
pub async fn update_later<R: Runtime>(app: AppHandle<R>) {
    updater::snooze(&app).await;
}

// ------------------------------------------------------------------ web sayfası köprüsü

#[derive(Serialize)]
pub struct DesktopInfo {
    version: String,
    mode: Mode,
}

#[tauri::command]
pub fn desktop_info<R: Runtime>(app: AppHandle<R>) -> DesktopInfo {
    DesktopInfo { version: env!("CARGO_PKG_VERSION").into(), mode: app.state::<AppCtx>().settings_snapshot().mode }
}

#[tauri::command]
pub fn desktop_notify<R: Runtime>(app: AppHandle<R>, title: String, body: String) -> Result<(), String> {
    let title: String = title.chars().take(120).collect();
    let body: String = body.chars().take(400).collect();
    app.notification().builder().title(title).body(body).show().map_err(|e| e.to_string())
}

#[tauri::command]
pub fn desktop_badge<R: Runtime>(window: WebviewWindow<R>, count: i64) -> Result<(), String> {
    let value = if count > 0 { Some(count.min(999)) } else { None };
    window.set_badge_count(value).map_err(|e| e.to_string())
}
