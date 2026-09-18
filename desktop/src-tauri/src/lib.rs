//! Erbaa Bilgi Eğitim masaüstü uygulaması (Tauri 2).
//!
//! İki kip:
//!   A) Kurum bilgisayarı (yerel kurulum): paketteki PHP + Laravel + SQLite, çevrimdışı, web ile eşitlenir.
//!   B) Çevrimiçi kullanım: kurum sunucusunu açan yerel pencere; yerel veri yok.
//! Mimari ve akışlar: docs/DESKTOP.md

mod commands;
mod menu;
mod paths;
mod php;
mod runtime;
mod secrets;
mod settings;
mod setup_flow;
mod updater;
mod window;

use paths::Paths;
use settings::{Mode, Settings};
use std::sync::atomic::{AtomicBool, AtomicU32, AtomicU64, Ordering};
use std::sync::{Arc, RwLock};
use tauri::{Manager, RunEvent, WindowEvent};
use tauri_plugin_deep_link::DeepLinkExt;

pub const DEFAULT_SERVER: &str = "https://kurs.bogahostdeveloper.com.tr";

/// Uygulama durumu (tauri State).
pub struct AppCtx {
    pub paths: Paths,
    pub settings: RwLock<Settings>,
    pub runtime: Arc<runtime::LocalRuntime>,
    pub pending_update: tokio::sync::Mutex<Option<tauri_plugin_updater::Update>>,
    pub last_error: RwLock<Option<window::ErrorInfo>>,
    /// Ana pencerede gezinmeye izin verilen kökenler (kurum sunucusu, yerel sunucu)
    pub allowed_origins: Arc<RwLock<Vec<String>>>,
    pub app_base_url: RwLock<Option<url::Url>>,
    /// Uygulamanın kendi indirdiği dosyalar (kartın "Aç"/"Klasörde göster" düğmeleri yalnız bunları açabilir)
    pub downloads: RwLock<window::Downloads>,
    pub capability_seq: AtomicU32,
    /// Ana penceredeki güncelleme şeridinin "buradayım" sayacı: `update_info` her çağrıldığında artar.
    /// Şerit yanıt vermezse (kurulum ekranı) updater ayrı pencereyi yedek yol olarak açar.
    pub banner_seen: AtomicU64,
    pub quitting: AtomicBool,
    pub setup_lock: tokio::sync::Mutex<()>,
    /// Derin bağlantıyla gelen, yerel sunucu hazır olunca açılacak yol
    pub pending_path: RwLock<Option<String>>,
}

impl AppCtx {
    pub fn settings_snapshot(&self) -> Settings {
        self.settings.read().map(|s| s.clone()).unwrap_or_default()
    }

    pub fn update_settings(&self, f: impl FnOnce(&mut Settings)) -> Result<(), String> {
        let mut guard = self.settings.write().map_err(|_| "Ayarlar kilitli.".to_string())?;
        f(&mut guard);
        guard.save(&self.paths.settings_file())
    }

    pub fn take_pending_path(&self) -> Option<String> {
        self.pending_path.write().ok().and_then(|mut p| p.take())
    }
}

/// erbaakurs://ac?yol=/ogrenciler/12  →  mevcut kökende /ogrenciler/12
fn handle_deep_link<R: tauri::Runtime>(app: &tauri::AppHandle<R>, urls: Vec<url::Url>) {
    for u in urls {
        if u.scheme() != "erbaakurs" {
            continue;
        }
        let path = u
            .query_pairs()
            .find(|(k, _)| k == "yol" || k == "path")
            .map(|(_, v)| v.to_string())
            .filter(|p| p.starts_with('/') && !p.starts_with("//") && p.len() < 512 && !p.contains(['\\', '\n', '\r']))
            .unwrap_or_else(|| "/".into());
        log::info!("Derin bağlantı: {path}");
        let ctx = app.state::<AppCtx>();
        window::focus_main(app);
        let Some(w) = window::main_window(app) else { continue };
        let current = w.url().ok();
        let on_web = current
            .as_ref()
            .map(|c| ctx.allowed_origins.read().map(|a| a.contains(&c.origin().ascii_serialization())).unwrap_or(false))
            .unwrap_or(false);
        if on_web {
            if let Some(mut target) = current {
                target.set_path(&path);
                target.set_query(None);
                let _ = w.navigate(target);
            }
        } else if let Ok(mut p) = ctx.pending_path.write() {
            *p = Some(path);
        }
    }
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let mut builder = tauri::Builder::default();

    #[cfg(desktop)]
    {
        // Tek örnek: ikinci açılış mevcut pencereyi öne getirir (derin bağlantı eklentisi URL'yi ayrıca iletir)
        builder = builder.plugin(tauri_plugin_single_instance::init(|app, _argv, _cwd| {
            window::focus_main(app);
        }));
    }

    let log_plugin = tauri_plugin_log::Builder::new()
        .targets([
            tauri_plugin_log::Target::new(tauri_plugin_log::TargetKind::LogDir { file_name: Some("uygulama".into()) }),
            tauri_plugin_log::Target::new(tauri_plugin_log::TargetKind::Stdout),
        ])
        .level(log::LevelFilter::Info)
        .max_file_size(5_000_000)
        .rotation_strategy(tauri_plugin_log::RotationStrategy::KeepSome(5))
        .timezone_strategy(tauri_plugin_log::TimezoneStrategy::UseLocal)
        .build();

    let app = builder
        .plugin(log_plugin)
        .plugin(tauri_plugin_deep_link::init())
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .plugin(tauri_plugin_autostart::init(tauri_plugin_autostart::MacosLauncher::LaunchAgent, Some(vec!["--hidden"])))
        .invoke_handler(tauri::generate_handler![
            commands::app_state,
            commands::check_server,
            commands::choose_remote,
            commands::setup_local,
            commands::resume_setup,
            commands::start_local,
            commands::reset_setup,
            commands::open_logs,
            commands::quit_app,
            commands::update_info,
            commands::update_install,
            commands::update_later,
            commands::desktop_info,
            commands::desktop_notify,
            commands::desktop_badge,
            commands::desktop_sync_now,
            commands::open_downloaded_path,
            commands::reveal_downloaded_path,
        ])
        .setup(|app| {
            let handle = app.handle().clone();
            let paths = Paths::resolve(&handle).map_err(std::io::Error::other)?;
            std::fs::create_dir_all(&paths.data)?;
            let settings = Settings::load(&paths.settings_file());
            log::info!(
                "Erbaa Kurs {} başlıyor · kip {:?} · veri {}",
                env!("CARGO_PKG_VERSION"),
                settings.mode,
                paths.data.display()
            );

            // Yerel kipte kurum sunucusu ana pencerede AÇILMAZ: tek bir bağlantı tıklaması pencereyi
            // internetteki siteye taşıyordu ve internet yokken geri dönüş yolu kalmıyordu.
            let mut origins = Vec::new();
            if settings.mode != Mode::Local {
                if let Some(o) = settings.server_url.as_deref().and_then(window::origin_of) {
                    origins.push(o);
                }
            }
            app.manage(AppCtx {
                paths,
                settings: RwLock::new(settings.clone()),
                runtime: Arc::new(runtime::LocalRuntime::default()),
                pending_update: tokio::sync::Mutex::new(None),
                last_error: RwLock::new(None),
                allowed_origins: Arc::new(RwLock::new(origins)),
                app_base_url: RwLock::new(None),
                downloads: RwLock::new(window::Downloads::default()),
                capability_seq: AtomicU32::new(1),
                banner_seen: AtomicU64::new(0),
                quitting: AtomicBool::new(false),
                setup_lock: tokio::sync::Mutex::new(()),
                pending_path: RwLock::new(None),
            });
            // Kayıtlı sunucu varsayılan değilse köprü iznini ver (yalnız çevrimiçi kipte)
            if settings.mode != Mode::Local {
                if let Some(o) = settings.server_url.as_deref().and_then(window::origin_of) {
                    window::allow_origin(&handle, &o);
                }
            }

            menu::install(&handle)?;

            let hidden = std::env::args().any(|a| a == "--hidden");
            let main = window::create_main(&handle, !hidden)?;
            if let Ok(u) = main.url() {
                if let Ok(mut b) = app.state::<AppCtx>().app_base_url.write() {
                    *b = Some(u);
                }
            }

            // Derin bağlantılar
            let dl = handle.clone();
            app.deep_link().on_open_url(move |event| handle_deep_link(&dl, event.urls()));
            if let Ok(Some(urls)) = app.deep_link().get_current() {
                handle_deep_link(&handle, urls);
            }

            // Kip B: doğrudan kurum sunucusu
            if settings.mode == Mode::Remote {
                if let Some(server) = settings.server_url.clone() {
                    let h = handle.clone();
                    tauri::async_runtime::spawn(async move {
                        let path = h.state::<AppCtx>().take_pending_path();
                        if let Err(e) = window::open_remote(&h, &server, path.as_deref()).await {
                            window::show_error(
                                &h,
                                window::ErrorInfo {
                                    code: "server_unreachable".into(),
                                    title: "Kurum sunucusuna ulaşılamıyor".into(),
                                    message: "İnternet bağlantınızı denetleyip yeniden deneyin. Sorun sürerse kurum yöneticinize haber verin.".into(),
                                    detail: Some(format!("{server}\n{e}")),
                                },
                            );
                        }
                    });
                }
            }
            // Kip A: kurulum ön yüzü "Başlatılıyor" ekranını açıp start_local'ı çağırır (hata olursa ekranda gösterir).
            // Gizli (oturum açılışı) başlatmada ön yüz yüklenmeyebilir → doğrudan başlat.
            if settings.mode == Mode::Local && settings.local_ready() && hidden {
                let h = handle.clone();
                tauri::async_runtime::spawn(async move {
                    if let Err(e) = setup_flow::start_and_open(&h).await {
                        log::error!("Arka planda başlatılamadı: {e}");
                    }
                });
            }

            updater::spawn_checker(handle.clone());
            Ok(())
        })
        .build(tauri::generate_context!())
        .expect("Erbaa Kurs başlatılamadı");

    app.run(|handle, event| match event {
        RunEvent::WindowEvent { label, event: WindowEvent::CloseRequested { api, .. }, .. } if label == window::MAIN => {
            // Pencereyi kapatmak uygulamayı kapatmaz: yerel kipte eşitleme arka planda sürer (tepsi/Dock'tan açılır)
            let ctx = handle.state::<AppCtx>();
            if !ctx.quitting.load(Ordering::SeqCst) {
                api.prevent_close();
                if let Some(w) = window::main_window(handle) {
                    let _ = w.hide();
                }
            }
        }
        // Pencere öne geldiğinde (en sık 15 dakikada bir) güncelleme denetimi
        RunEvent::WindowEvent { label, event: WindowEvent::Focused(true), .. } if label == window::MAIN => {
            updater::check_on_focus(handle);
        }
        RunEvent::ExitRequested { api, code, .. } => {
            let ctx = handle.state::<AppCtx>();
            if !ctx.quitting.load(Ordering::SeqCst) {
                // Kullanıcı kaynaklı (Cmd+Q, Dock › Çık): önce yerel sunucuyu düzgün durdur
                api.prevent_exit();
                if code.is_none() || code == Some(0) {
                    commands::request_quit(handle.clone());
                }
            }
        }
        #[cfg(target_os = "macos")]
        RunEvent::Reopen { .. } => window::focus_main(handle),
        RunEvent::Exit => {
            handle.state::<AppCtx>().runtime.kill_now();
        }
        _ => {}
    });
}
