//! Uygulama menüsü (macOS menü çubuğu) ve tepsi (menü çubuğu simgesi).

use crate::settings::Mode;
use crate::{commands, updater, window, AppCtx};
use tauri::menu::{AboutMetadata, CheckMenuItem, Menu, MenuEvent, MenuItem, PredefinedMenuItem, Submenu};
use tauri::tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Manager, Runtime};
use tauri_plugin_autostart::ManagerExt as AutostartExt;

pub const TRAY_ID: &str = "kurs-tray";

pub fn install<R: Runtime>(app: &AppHandle<R>) -> tauri::Result<()> {
    let autostart_on = app.autolaunch().is_enabled().unwrap_or(false);

    // --- Menü çubuğu
    let about = PredefinedMenuItem::about(
        app,
        Some("Erbaa Kurs hakkında"),
        Some(AboutMetadata {
            name: Some("Erbaa Bilgi Eğitim".into()),
            version: Some(env!("CARGO_PKG_VERSION").into()),
            copyright: Some("© Erbaa Bilgi Eğitim".into()),
            ..Default::default()
        }),
    )?;
    let app_menu = Submenu::with_items(
        app,
        "Erbaa Kurs",
        true,
        &[
            &about,
            &MenuItem::with_id(app, "check-updates", "Güncellemeleri denetle…", true, None::<&str>)?,
            &PredefinedMenuItem::separator(app)?,
            &CheckMenuItem::with_id(app, "autostart", "Oturum açılınca başlat", true, autostart_on, None::<&str>)?,
            &PredefinedMenuItem::separator(app)?,
            &PredefinedMenuItem::hide(app, Some("Erbaa Kurs'u gizle"))?,
            &PredefinedMenuItem::hide_others(app, Some("Diğerlerini gizle"))?,
            &PredefinedMenuItem::show_all(app, Some("Tümünü göster"))?,
            &PredefinedMenuItem::separator(app)?,
            &MenuItem::with_id(app, "quit", "Erbaa Kurs'tan çık", true, Some("CmdOrCtrl+Q"))?,
        ],
    )?;
    // Düzen menüsü olmadan macOS'ta Cmd+C / Cmd+V metin kutularında çalışmaz
    let edit_menu = Submenu::with_items(
        app,
        "Düzen",
        true,
        &[
            &PredefinedMenuItem::undo(app, Some("Geri al"))?,
            &PredefinedMenuItem::redo(app, Some("Yinele"))?,
            &PredefinedMenuItem::separator(app)?,
            &PredefinedMenuItem::cut(app, Some("Kes"))?,
            &PredefinedMenuItem::copy(app, Some("Kopyala"))?,
            &PredefinedMenuItem::paste(app, Some("Yapıştır"))?,
            &PredefinedMenuItem::select_all(app, Some("Tümünü seç"))?,
        ],
    )?;
    let view_menu = Submenu::with_items(
        app,
        "Görünüm",
        true,
        &[
            &MenuItem::with_id(app, "reload", "Sayfayı yenile", true, Some("CmdOrCtrl+R"))?,
            &MenuItem::with_id(app, "home", "Ana sayfa", true, Some("CmdOrCtrl+Shift+H"))?,
            &PredefinedMenuItem::separator(app)?,
            &PredefinedMenuItem::fullscreen(app, Some("Tam ekran"))?,
        ],
    )?;
    let data_menu = Submenu::with_items(
        app,
        "Eşitleme",
        true,
        &[
            &MenuItem::with_id(app, "sync-now", "Şimdi eşitle", true, Some("CmdOrCtrl+Shift+S"))?,
            &PredefinedMenuItem::separator(app)?,
            &MenuItem::with_id(app, "setup", "Kullanım biçimi ve kurulum…", true, None::<&str>)?,
        ],
    )?;
    let window_menu = Submenu::with_items(
        app,
        "Pencere",
        true,
        &[
            &PredefinedMenuItem::minimize(app, Some("Küçült"))?,
            &PredefinedMenuItem::maximize(app, Some("Büyüt"))?,
            &PredefinedMenuItem::close_window(app, Some("Pencereyi kapat"))?,
        ],
    )?;
    let help_menu = Submenu::with_items(
        app,
        "Yardım",
        true,
        &[
            &MenuItem::with_id(app, "open-logs", "Günlük klasörünü aç", true, None::<&str>)?,
            &MenuItem::with_id(app, "open-web", "Web sürümünü tarayıcıda aç", true, None::<&str>)?,
        ],
    )?;
    let menu = Menu::with_items(app, &[&app_menu, &edit_menu, &view_menu, &data_menu, &window_menu, &help_menu])?;
    app.set_menu(menu)?;
    app.on_menu_event(|app, event| handle(app, event));

    // --- Tepsi
    let tray_menu = Menu::with_items(
        app,
        &[
            &MenuItem::with_id(app, "show", "Pencereyi göster", true, None::<&str>)?,
            &MenuItem::with_id(app, "sync-now", "Şimdi eşitle", true, None::<&str>)?,
            &MenuItem::with_id(app, "check-updates", "Güncellemeleri denetle", true, None::<&str>)?,
            &PredefinedMenuItem::separator(app)?,
            &MenuItem::with_id(app, "open-logs", "Günlük klasörünü aç", true, None::<&str>)?,
            &PredefinedMenuItem::separator(app)?,
            &MenuItem::with_id(app, "quit", "Çıkış", true, None::<&str>)?,
        ],
    )?;
    let mut tray = TrayIconBuilder::<R>::with_id(TRAY_ID)
        .tooltip("Erbaa Bilgi Eğitim")
        .menu(&tray_menu)
        .show_menu_on_left_click(false)
        .on_menu_event(|app, event| handle(app, event))
        .on_tray_icon_event(|tray, event| {
            if let TrayIconEvent::Click { button: MouseButton::Left, button_state: MouseButtonState::Up, .. } = event {
                window::focus_main(tray.app_handle());
            }
        });
    if let Some(icon) = app.default_window_icon() {
        tray = tray.icon(icon.clone());
    }
    tray.build(app)?;
    Ok(())
}

fn handle<R: Runtime>(app: &AppHandle<R>, event: MenuEvent) {
    match event.id().as_ref() {
        "show" => window::focus_main(app),
        "quit" => commands::request_quit(app.clone()),
        "check-updates" => {
            let app = app.clone();
            tauri::async_runtime::spawn(async move { updater::check(&app, true).await });
        }
        "autostart" => {
            let al = app.autolaunch();
            let enabled = al.is_enabled().unwrap_or(false);
            let res = if enabled { al.disable() } else { al.enable() };
            if let Err(e) = res {
                log::warn!("Otomatik başlatma değiştirilemedi: {e}");
            }
        }
        "reload" => {
            if let Some(w) = window::main_window(app) {
                let _ = w.eval("window.location.reload()");
            }
        }
        "home" => {
            // Yerel kipte pencere her koşulda paketteki yerel sunucuya döner (açılış jetonuyla birlikte);
            // çevrimiçi kipte sayfanın kendi kökünde kalır.
            let local = app.state::<AppCtx>().settings.read().map(|s| s.mode == Mode::Local).unwrap_or(false);
            if local {
                let h = app.clone();
                tauri::async_runtime::spawn(async move {
                    if let Err(e) = crate::setup_flow::start_and_open(&h).await {
                        log::warn!("Yerel uygulamaya dönülemedi: {e}");
                    }
                });
            } else if let Some(w) = window::main_window(app) {
                let _ = w.eval("window.location.assign('/')");
            }
        }
        "open-logs" => commands::open_logs_dir(app),
        "open-web" => {
            use tauri_plugin_opener::OpenerExt;
            let url = app.state::<AppCtx>().settings.read().ok().and_then(|s| s.server_url.clone()).unwrap_or_else(|| crate::DEFAULT_SERVER.into());
            let _ = app.opener().open_url(url, None::<&str>);
        }
        "setup" => window::show_setup(app, "screen=setup"),
        "sync-now" => {
            let app = app.clone();
            tauri::async_runtime::spawn(async move { crate::commands::run_sync_now(&app, true).await });
        }
        _ => {}
    }
}
