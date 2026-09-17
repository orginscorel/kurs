//! Otomatik güncelleme (tauri-plugin-updater). Bildirim: https://kurs.bogahostdeveloper.com.tr/desktop/latest.json
//! Açılıştan 20 sn sonra, saatte bir ve ana pencere odağa geldiğinde (en sık 15 dakikada bir) denetlenir.
//! Yeni sürüm bulununca ana penceredeki şeride (`update://available`, src/bridge.js) haber verilir; şerit
//! yanıt vermezse (kurulum/hata ekranı ya da enjeksiyon engellenmiş) yedek yol olarak ayrı pencere açılır.
//! "Daha sonra" aynı sürüm için 24 saat susturur. Kurulumdan önce yerel sunucu düzgün durdurulur.

use crate::{window, AppCtx};
use serde::Serialize;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{Duration, SystemTime, UNIX_EPOCH};
use tauri::{AppHandle, Emitter, Manager, Runtime};
use tauri_plugin_notification::NotificationExt;
use tauri_plugin_updater::UpdaterExt;

const FIRST_CHECK: Duration = Duration::from_secs(20);
const INTERVAL: Duration = Duration::from_secs(60 * 60);
/// Ana pencere odağa geldiğinde denetim: en sık bu aralıkla.
const FOCUS_MIN_GAP: u64 = 15 * 60;
/// Şerit bu süre içinde `update_info` ile kendini bildirmezse ayrı pencere açılır.
const BANNER_WAIT: Duration = Duration::from_millis(2500);
const SNOOZE_SECS: u64 = 24 * 60 * 60;

/// Son denetim zamanı (unix sn) — odak denetimini sınırlar.
static LAST_CHECK: AtomicU64 = AtomicU64::new(0);

#[derive(Clone, Serialize)]
pub struct UpdateInfo {
    pub version: String,
    pub current_version: String,
    pub date: Option<String>,
    pub notes: Option<String>,
    /// Kullanıcı bu sürüm için "Daha sonra" dediyse (şerit kendiliğinden geri gelmesin diye)
    pub snoozed: bool,
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

/// Ana pencere odağa geldi: son denetimin üzerinden 15 dakika geçtiyse sessizce yeniden denetle.
pub fn check_on_focus<R: Runtime>(app: &AppHandle<R>) {
    if now().saturating_sub(LAST_CHECK.load(Ordering::SeqCst)) < FOCUS_MIN_GAP {
        return;
    }
    let app = app.clone();
    tauri::async_runtime::spawn(async move { check(&app, false).await });
}

/// Ana penceredeki şeride haber verir. Şerit `update_info` çağırarak kendini bildirmezse
/// (kurulum ekranı, izinsiz köken, enjeksiyon engeli) eski ayrı pencere yedek yol olarak açılır.
async fn present<R: Runtime>(app: &AppHandle<R>, event: &str, payload: Option<UpdateInfo>) {
    let seen = app.state::<AppCtx>().banner_seen.load(Ordering::SeqCst);
    let sent = match payload {
        Some(info) => app.emit(event, info),
        None => app.emit(event, ()),
    };
    if let Err(e) = sent {
        log::warn!("Güncelleme olayı gönderilemedi: {e}");
    }
    tokio::time::sleep(BANNER_WAIT).await;
    if app.state::<AppCtx>().banner_seen.load(Ordering::SeqCst) == seen {
        log::info!("Güncelleme şeridi yanıt vermedi; ayrı pencere açılıyor.");
        window::open_update_window(app);
    }
}

/// `manual`: menüden "Güncellemeleri denetle" — sonuç ne olursa olsun kullanıcıya gösterilir.
pub async fn check<R: Runtime>(app: &AppHandle<R>, manual: bool) {
    LAST_CHECK.store(now(), Ordering::SeqCst);
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
                if let Some(i) = info(app).await {
                    present(app, "update://available", Some(i)).await;
                }
            }
        }
        Ok(None) => {
            *ctx.pending_update.lock().await = None;
            if manual {
                present(app, "update://none", None).await; // "Uygulama güncel"
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
    let s = ctx.settings_snapshot();
    let guard = ctx.pending_update.lock().await;
    guard.as_ref().map(|u| UpdateInfo {
        snoozed: s.snoozed_version.as_deref() == Some(u.version.as_str()) && s.update_snoozed_until.unwrap_or(0) > now(),
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
    let _ = app.emit("update://dismissed", ());
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
