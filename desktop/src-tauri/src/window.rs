//! Ana pencere, gezinme koruması, hata ekranı, çevrimiçi kip açılışı, güncelleme penceresi.

use crate::AppCtx;
use serde::Serialize;
use std::path::{Path, PathBuf};
use tauri::ipc::CapabilityBuilder;
use tauri::webview::DownloadEvent;
use tauri::{AppHandle, Emitter, Manager, Runtime, WebviewUrl, WebviewWindow, WebviewWindowBuilder};
use tauri_plugin_notification::NotificationExt;
use tauri_plugin_opener::OpenerExt;

pub const MAIN: &str = "main";
pub const UPDATE: &str = "update";
const BRIDGE_JS: &str = include_str!("bridge.js");

/// Açılmasına izin verilen son indirmeler. `open_downloaded_path` / `reveal_downloaded_path`
/// yalnız bu listedeki yolları kabul eder: web sayfası rastgele bir dosyayı açtıramaz.
#[derive(Default)]
pub struct Downloads {
    /// macOS'ta `DownloadEvent::Finished` yolu boş gelir (WKWebView sınırı); hedefi istekte kaydedip
    /// bitişte adres (URL) ile eşleştiriyoruz.
    pending: Vec<(String, PathBuf)>,
    /// İzinli yollar, en eskisi başta.
    done: Vec<PathBuf>,
}

const KEEP_DOWNLOADS: usize = 20;
const KEEP_PENDING: usize = 32;

impl Downloads {
    fn remember(&mut self, url: &str, dest: PathBuf) {
        if self.pending.len() >= KEEP_PENDING {
            self.pending.remove(0);
        }
        self.pending.push((url.to_string(), dest));
    }

    fn take(&mut self, url: &str, path: Option<PathBuf>) -> Option<PathBuf> {
        match self.pending.iter().position(|(u, _)| u == url) {
            Some(i) => Some(self.pending.remove(i).1),
            None => path,
        }
    }

    fn allow(&mut self, p: PathBuf) {
        self.done.retain(|x| x != &p);
        self.done.push(p);
        if self.done.len() > KEEP_DOWNLOADS {
            self.done.remove(0);
        }
    }

    fn is_allowed(&self, p: &Path) -> bool {
        self.done.iter().any(|x| x == p)
    }
}

/// Yol bu uygulamanın indirdiği (ve hâlâ duran) bir dosya mı? Değilse `None`.
pub fn allowed_download<R: Runtime>(app: &AppHandle<R>, path: &str) -> Option<PathBuf> {
    let p = PathBuf::from(path);
    let ok = app.state::<AppCtx>().downloads.read().map(|d| d.is_allowed(&p)).unwrap_or(false);
    if ok && p.is_file() {
        Some(p)
    } else {
        None
    }
}

/// Ana pencerenin sağ altındaki indirme kartına (src/bridge.js) gönderilen bilgi.
#[derive(Clone, Serialize)]
struct DownloadDone {
    ok: bool,
    path: Option<String>,
    name: String,
    size: Option<u64>,
}

#[derive(Clone, Debug, Serialize)]
pub struct ErrorInfo {
    pub code: String,
    pub title: String,
    pub message: String,
    pub detail: Option<String>,
}

pub fn origin_of(url: &str) -> Option<String> {
    let u = url::Url::parse(url).ok()?;
    Some(u.origin().ascii_serialization())
}

fn is_app_url(url: &url::Url) -> bool {
    match url.scheme() {
        "tauri" | "asset" => true,
        "http" | "https" => matches!(url.host_str(), Some("tauri.localhost")) || (cfg!(dev) && matches!(url.host_str(), Some("localhost")) && url.port() == Some(5183)),
        _ => false,
    }
}

pub fn create_main<R: Runtime>(app: &AppHandle<R>, visible: bool) -> tauri::Result<WebviewWindow<R>> {
    let allowed = app.state::<AppCtx>().allowed_origins.clone();
    let nav_app = app.clone();
    let dl_app = app.clone();
    WebviewWindowBuilder::new(app, MAIN, WebviewUrl::App("index.html".into()))
        .title("Erbaa Bilgi Eğitim")
        .inner_size(1360.0, 860.0)
        .min_inner_size(960.0, 640.0)
        .center()
        .visible(visible)
        .initialization_script(BRIDGE_JS)
        .on_navigation(move |url| {
            if is_app_url(url) || url.scheme() == "about" || url.scheme() == "blob" || url.scheme() == "data" {
                return true;
            }
            let origin = url.origin().ascii_serialization();
            if allowed.read().map(|a| a.iter().any(|o| o == &origin)).unwrap_or(false) {
                return true;
            }
            // Diğer her şey (wa.me, tel:, mailto:, dış siteler) sistem tarayıcısında/uygulamasında açılır
            log::info!("Dış bağlantı sistemde açılıyor: {}", url.as_str());
            let _ = nav_app.opener().open_url(url.as_str(), None::<&str>);
            false
        })
        .on_download(move |_webview, event| {
            match event {
                DownloadEvent::Requested { url, destination } => {
                    let suggested = destination
                        .file_name()
                        .map(|n| n.to_string_lossy().to_string())
                        .filter(|n| !n.is_empty())
                        .or_else(|| url.path_segments().and_then(|mut s| s.next_back().map(str::to_string)))
                        .unwrap_or_else(|| "indirilen-dosya".into());
                    let dir = dl_app.path().download_dir().unwrap_or_else(|_| std::env::temp_dir());
                    *destination = unique_path(dir, &suggested);
                    if let Ok(mut d) = dl_app.state::<AppCtx>().downloads.write() {
                        d.remember(url.as_str(), destination.clone());
                    }
                    log::info!("İndirme: {} → {}", url, destination.display());
                }
                DownloadEvent::Finished { url, path, success } => {
                    let file = dl_app.state::<AppCtx>().downloads.write().ok().and_then(|mut d| d.take(url.as_str(), path));
                    let name = file
                        .as_ref()
                        .and_then(|p| p.file_name())
                        .map(|n| n.to_string_lossy().to_string())
                        .unwrap_or_else(|| "İndirilen dosya".into());
                    let size = file.as_ref().and_then(|p| std::fs::metadata(p).ok()).map(|m| m.len());
                    if success {
                        if let Some(p) = file.clone() {
                            if let Ok(mut d) = dl_app.state::<AppCtx>().downloads.write() {
                                d.allow(p);
                            }
                        }
                    }
                    log::info!("İndirme bitti ({}): {}", if success { "tamam" } else { "başarısız" }, name);
                    let _ = dl_app.emit(
                        "download://done",
                        DownloadDone {
                            ok: success,
                            path: if success { file.as_ref().map(|p| p.to_string_lossy().to_string()) } else { None },
                            name: name.clone(),
                            size: if success { size } else { None },
                        },
                    );
                    // Uygulama arkadayken de görünsün diye macOS bildirimi kalıyor (tıklanınca açma:
                    // tauri-plugin-notification masaüstünde tıklama geri çağrısı sunmuyor, bkz. docs/DESKTOP.md).
                    let (title, body) = if success {
                        ("İndirme tamamlandı", format!("{name} · İndirilenler klasörüne kaydedildi."))
                    } else {
                        ("İndirme tamamlanamadı", "Dosya kaydedilemedi. Tekrar deneyin.".to_string())
                    };
                    let _ = dl_app.notification().builder().title(title).body(body).show();
                }
                _ => {}
            }
            true
        })
        .build()
}

fn unique_path(dir: PathBuf, name: &str) -> PathBuf {
    let clean: String = name.chars().map(|c| if matches!(c, '/' | '\\' | ':' | '\0') { '-' } else { c }).collect();
    let candidate = dir.join(&clean);
    if !candidate.exists() {
        return candidate;
    }
    let (stem, ext) = match clean.rsplit_once('.') {
        Some((s, e)) if !s.is_empty() => (s.to_string(), format!(".{e}")),
        _ => (clean.clone(), String::new()),
    };
    for i in 2..1000 {
        let p = dir.join(format!("{stem} ({i}){ext}"));
        if !p.exists() {
            return p;
        }
    }
    dir.join(clean)
}

pub fn main_window<R: Runtime>(app: &AppHandle<R>) -> Option<WebviewWindow<R>> {
    app.get_webview_window(MAIN)
}

pub fn focus_main<R: Runtime>(app: &AppHandle<R>) {
    if let Some(w) = main_window(app) {
        let _ = w.show();
        let _ = w.unminimize();
        let _ = w.set_focus();
    }
}

/// Uygulamanın kendi sayfası (kurulum ön yüzü) adresi.
pub fn app_page_url<R: Runtime>(app: &AppHandle<R>, query: &str) -> url::Url {
    let base = app.state::<AppCtx>().app_base_url.read().ok().and_then(|b| b.clone());
    let mut u = base.unwrap_or_else(|| url::Url::parse("tauri://localhost/index.html").expect("url"));
    u.set_path("/index.html");
    u.set_query(if query.is_empty() { None } else { Some(query) });
    u.set_fragment(None);
    u
}

pub fn show_setup<R: Runtime>(app: &AppHandle<R>, query: &str) {
    if let Some(w) = main_window(app) {
        let _ = w.navigate(app_page_url(app, query));
        focus_main(app);
    }
}

pub fn show_error<R: Runtime>(app: &AppHandle<R>, err: ErrorInfo) {
    log::error!("Hata ekranı: {} — {} {}", err.title, err.message, err.detail.clone().unwrap_or_default());
    if let Ok(mut e) = app.state::<AppCtx>().last_error.write() {
        *e = Some(err);
    }
    show_setup(app, "screen=error");
}

/// Adrese yetenek (yalnız köprü komutları) ekler ve gezinme listesine alır.
pub fn allow_origin<R: Runtime>(app: &AppHandle<R>, origin: &str) {
    let ctx = app.state::<AppCtx>();
    let mut list = match ctx.allowed_origins.write() {
        Ok(l) => l,
        Err(_) => return,
    };
    if list.iter().any(|o| o == origin) {
        return;
    }
    list.push(origin.to_string());
    drop(list);
    // Varsayılan sunucu ve 127.0.0.1 capabilities/web-bridge.json içinde; diğerleri çalışma anında
    let static_ok = origin == crate::DEFAULT_SERVER || origin.starts_with("http://127.0.0.1:");
    if !static_ok {
        let id = format!("web-bridge-{}", ctx.capability_seq.fetch_add(1, std::sync::atomic::Ordering::SeqCst));
        let cap = CapabilityBuilder::new(id)
            .remote(format!("{origin}/*"))
            .window(MAIN)
            .permission("allow-desktop-info")
            .permission("allow-desktop-notify")
            .permission("allow-desktop-badge")
            // Sayfa üstündeki güncelleme şeridi (bridge.js)
            .permission("allow-update-info")
            .permission("allow-update-install")
            .permission("allow-update-later")
            .permission("core:event:allow-listen")
            .permission("core:event:allow-unlisten")
            // Sağ alttaki indirme kartı (yalnız uygulamanın kendi indirdiği dosyalar)
            .permission("allow-open-downloaded-path")
            .permission("allow-reveal-downloaded-path");
        if let Err(e) = app.add_capability(cap) {
            log::warn!("Köprü izni eklenemedi ({origin}): {e}");
        }
    }
}

pub async fn ping_server(server_url: &str) -> Result<serde_json::Value, String> {
    let client = reqwest::Client::builder()
        .timeout(std::time::Duration::from_secs(12))
        .connect_timeout(std::time::Duration::from_secs(8))
        .user_agent(concat!("ErbaaKurs-Desktop/", env!("CARGO_PKG_VERSION")))
        .build()
        .map_err(|e| e.to_string())?;
    let url = format!("{}/api/v1/sync/ping", server_url.trim_end_matches('/'));
    let res = client.get(&url).header("Accept", "application/json").send().await.map_err(|e| {
        if e.is_timeout() {
            "Sunucu zamanında yanıt vermedi.".to_string()
        } else if e.is_connect() {
            "Sunucuya bağlanılamadı. İnternet bağlantınızı ve adresi denetleyin.".to_string()
        } else {
            format!("Sunucuya ulaşılamadı: {e}")
        }
    })?;
    if !res.status().is_success() {
        return Err(format!("Sunucu beklenmeyen yanıt verdi (HTTP {}). Adresin doğru olduğundan emin olun.", res.status().as_u16()));
    }
    let v: serde_json::Value = res.json().await.map_err(|_| "Bu adres Erbaa Bilgi Eğitim sunucusu gibi görünmüyor.".to_string())?;
    if v.get("app").and_then(|a| a.as_str()) != Some("kurs") {
        return Err("Bu adres Erbaa Bilgi Eğitim sunucusu gibi görünmüyor.".into());
    }
    Ok(v)
}

/// Çevrimiçi kip: sunucuyu yokla, izin ver, pencereyi yönlendir.
pub async fn open_remote<R: Runtime>(app: &AppHandle<R>, server_url: &str, path: Option<&str>) -> Result<(), String> {
    ping_server(server_url).await?;
    let origin = origin_of(server_url).ok_or("Sunucu adresi geçersiz.")?;
    allow_origin(app, &origin);
    let mut target = url::Url::parse(&origin).map_err(|e| e.to_string())?;
    if let Some(p) = path.filter(|p| p.starts_with('/') && !p.starts_with("//")) {
        target = target.join(p).map_err(|e| e.to_string())?;
    }
    let w = main_window(app).ok_or("Ana pencere yok.")?;
    w.navigate(target).map_err(|e| e.to_string())?;
    focus_main(app);
    Ok(())
}

pub fn open_update_window<R: Runtime>(app: &AppHandle<R>) {
    if let Some(w) = app.get_webview_window(UPDATE) {
        let _ = w.show();
        let _ = w.set_focus();
        return;
    }
    let built = WebviewWindowBuilder::new(app, UPDATE, WebviewUrl::App("update.html".into()))
        .title("Güncelleme")
        .inner_size(560.0, 600.0)
        .min_inner_size(460.0, 420.0)
        .resizable(true)
        .center()
        .build();
    if let Err(e) = built {
        log::error!("Güncelleme penceresi açılamadı: {e}");
    }
}
