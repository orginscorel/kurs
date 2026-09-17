//! Otomatik güncelleme (tauri-plugin-updater). Bildirim: https://kurs.bogahostdeveloper.com.tr/desktop/latest.json
//! Açılıştan 20 sn sonra ve 6 saatte bir denetlenir; yeni sürüm varsa "Yeni sürüm yayında" penceresi açılır.
//! "Daha sonra" aynı sürüm için 24 saat susturur. Kurulumdan önce yerel sunucu düzgün durdurulur.

use crate::{window, AppCtx};
use serde::Serialize;
use std::sync::atomic::Ordering;
use std::time::{Duration, SystemTime, UNIX_EPOCH};
use tauri::{AppHandle, Emitter, Manager, Runtime};
use tauri_plugin_notification::NotificationExt;
use tauri_plugin_updater::UpdaterExt;

const FIRST_CHECK: Duration = Duration::from_secs(20);
const INTERVAL: Duration = Duration::from_secs(6 * 60 * 60);
const SNOOZE_SECS: u64 = 24 * 60 * 60;

#[derive(Clone, Serialize)]
pub struct UpdateInfo {
    pub version: String,
    pub current_version: String,
    pub date: Option<String>,
    pub notes: Option<String>,
}

#[derive(Clone, Serialize)]
struct Progress {
    downloaded: u64,
    total: Option<u64>,
    phase: &'static str,
}

fn now() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0)
}

pub fn spawn_checker<R: Runtime>(app: AppHandle<R>) {
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(FIRST_CHECK).await;
        loop {
            check(&app, false).await;
            tokio::time::sleep(INTERVAL).await;
        }
    });
}

/// `manual`: menüden "Güncellemeleri denetle" — sonuç ne olursa olsun kullanıcıya gösterilir.
pub async fn check<R: Runtime>(app: &AppHandle<R>, manual: bool) {
    let ctx = app.state::<AppCtx>();
    let updater = match app.updater() {
        Ok(u) => u,
        Err(e) => {
            log::warn!("Güncelleyici hazır değil: {e}");
            return;
        }
    };
    match updater.check().await {
        Ok(Some(update)) => {
            log::info!("Yeni sürüm: {} (kurulu {})", update.version, update.current_version);
            let version = update.version.clone();
            *ctx.pending_update.lock().await = Some(update);
            let snoozed = {
                let s = ctx.settings.read().map(|s| s.clone()).unwrap_or_default();
                s.snoozed_version.as_deref() == Some(version.as_str()) && s.update_snoozed_until.unwrap_or(0) > now()
            };
            if manual || !snoozed {
                window::open_update_window(app);
            }
        }
        Ok(None) => {
            *ctx.pending_update.lock().await = None;
            if manual {
                window::open_update_window(app); // "Uygulama güncel"
            }
        }
        Err(e) => {
            log::warn!("Güncelleme denetimi başarısız: {e}");
            if manual {
                let _ = app
                    .notification()
                    .builder()
                    .title("Güncelleme denetlenemedi")
                    .body("Sunucuya ulaşılamadı. İnternet bağlantınızı denetleyip yeniden deneyin.")
                    .show();
            }
        }
    }
}

pub async fn info<R: Runtime>(app: &AppHandle<R>) -> Option<UpdateInfo> {
    let ctx = app.state::<AppCtx>();
    let guard = ctx.pending_update.lock().await;
    guard.as_ref().map(|u| UpdateInfo {
        version: u.version.clone(),
        current_version: u.current_version.clone(),
        date: u.date.map(|d| d.to_string()),
        notes: u.body.clone(),
    })
}

pub async fn snooze<R: Runtime>(app: &AppHandle<R>) {
    let ctx = app.state::<AppCtx>();
    let version = ctx.pending_update.lock().await.as_ref().map(|u| u.version.clone());
    if let Some(v) = version {
        let _ = ctx.update_settings(|s| {
            s.snoozed_version = Some(v);
            s.update_snoozed_until = Some(now() + SNOOZE_SECS);
        });
    }
    if let Some(w) = app.get_webview_window(window::UPDATE) {
        let _ = w.close();
    }
}

pub async fn install<R: Runtime>(app: &AppHandle<R>) -> Result<(), String> {
    let ctx = app.state::<AppCtx>();
    let update = ctx.pending_update.lock().await.clone().ok_or("Kurulacak güncelleme yok.")?;

    let mut downloaded: u64 = 0;
    let emitter = app.clone();
    let bytes = update
        .download(
            |chunk, total| {
                downloaded += chunk as u64;
                let _ = emitter.emit("update://progress", Progress { downloaded, total, phase: "downloading" });
            },
            || {},
        )
        .await
        .map_err(|e| format!("Güncelleme indirilemedi: {e}"))?;

    let _ = app.emit("update://progress", Progress { downloaded, total: Some(downloaded), phase: "installing" });
    // Yerel sunucu ve eşitleme işleri güvenle dursun (SQLite yazımı yarıda kalmasın)
    ctx.runtime.stop().await;
    update.install(bytes).map_err(|e| format!("Güncelleme kurulamadı: {e}"))?;

    let _ = app.emit("update://progress", Progress { downloaded, total: Some(downloaded), phase: "restarting" });
    ctx.quitting.store(true, Ordering::SeqCst);
    log::info!("Güncelleme kuruldu; yeniden başlatılıyor.");
    tokio::time::sleep(Duration::from_millis(400)).await;
    app.restart();
}
