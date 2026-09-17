//! Gömülü PHP ile artisan komutları ve ortam değişkenleri.
//!
//! Tüm PHP süreçleri (yerel sunucu, artisan, zamanlayıcının arka plana attığı `kurs:sync`) aynı ortamı alır:
//! PHPRC → paketteki runtime/php.ini (yol içeren ayarlar ${KURS_…} değişkenleriyle), .env içeriği, sırlar.
//! Laravel .env dosyasını KENDİSİ okumaz (paket klasöründe .env yok); değerleri bu modül verir.

use crate::paths::Paths;
use crate::secrets::LocalSecrets;
use std::collections::BTreeMap;
use std::path::Path;
use std::process::Stdio;
use std::time::Duration;
use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
use tokio::process::Command;

/// `.env` biçimli dosyayı okur: KEY=VALUE, # yorum, tırnaklı değerler. ${…} genişletmesi yapılmaz.
pub fn parse_env_file(path: &Path) -> BTreeMap<String, String> {
    let mut out = BTreeMap::new();
    let Ok(raw) = std::fs::read_to_string(path) else { return out };
    for line in raw.lines() {
        let line = line.trim();
        if line.is_empty() || line.starts_with('#') {
            continue;
        }
        let line = line.strip_prefix("export ").unwrap_or(line);
        let Some((k, v)) = line.split_once('=') else { continue };
        let k = k.trim();
        if k.is_empty() || !k.chars().all(|c| c.is_ascii_alphanumeric() || c == '_') {
            continue;
        }
        let v = v.trim();
        let v = if v.len() >= 2 && ((v.starts_with('"') && v.ends_with('"')) || (v.starts_with('\'') && v.ends_with('\''))) {
            v[1..v.len() - 1].replace("\\\"", "\"")
        } else {
            // satır sonu yorumu
            v.split(" #").next().unwrap_or("").trim().to_string()
        };
        out.insert(k.to_string(), v);
    }
    out
}

/// `.env` şablonundaki değişkenleri doldurup yazar. Değerler tırnaklanır.
pub fn write_env_file(paths: &Paths, server_url: &str) -> Result<(), String> {
    let template = std::fs::read_to_string(paths.env_template())
        .map_err(|e| format!("Yapılandırma şablonu okunamadı: {e}"))?;
    let quote = |s: &str| format!("\"{}\"", s.replace('\\', "\\\\").replace('"', "\\\""));
    let content = template
        .replace("{{DB_PATH}}", &quote(&paths.db_file().to_string_lossy()))
        .replace("{{SERVER_URL}}", &quote(server_url));
    if let Some(dir) = paths.env_file().parent() {
        std::fs::create_dir_all(dir).map_err(|e| e.to_string())?;
    }
    std::fs::write(paths.env_file(), content).map_err(|e| format!(".env yazılamadı: {e}"))?;
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        let _ = std::fs::set_permissions(paths.env_file(), std::fs::Permissions::from_mode(0o600));
    }
    Ok(())
}

/// PHP süreçlerinin ortamı.
pub fn build_env(paths: &Paths, secrets: &LocalSecrets, extra: &[(&str, String)]) -> Vec<(String, String)> {
    let mut env: BTreeMap<String, String> = BTreeMap::new();

    // Ana süreçten yalnız zararsız değişkenler
    for key in ["HOME", "USER", "LOGNAME", "TMPDIR", "LANG", "LC_ALL", "SHELL", "HTTPS_PROXY", "HTTP_PROXY", "NO_PROXY", "https_proxy", "http_proxy", "no_proxy"] {
        if let Ok(v) = std::env::var(key) {
            env.insert(key.into(), v);
        }
    }
    env.insert("PATH".into(), "/usr/bin:/bin:/usr/sbin:/sbin".into());
    env.entry("LANG".into()).or_insert_with(|| "tr_TR.UTF-8".into());

    // .env (sır yok)
    env.extend(parse_env_file(&paths.env_file()));

    let s = |p: &Path| p.to_string_lossy().to_string();
    let cache = paths.cache_dir();
    let fixed = [
        // php.ini ve içindeki ${…} değişkenleri
        ("PHPRC", s(&paths.runtime_dir())),
        ("PHP_INI_SCAN_DIR", String::new()),
        ("KURS_PREPEND", s(&paths.prepend())),
        ("KURS_CACERT", s(&paths.cacert())),
        ("KURS_PHP_ERROR_LOG", s(&paths.php_error_log())),
        ("SSL_CERT_FILE", s(&paths.cacert())),
        // salt okunur paket → yazılabilir yollar
        ("LARAVEL_STORAGE_PATH", s(&paths.storage())),
        ("APP_SERVICES_CACHE", s(&cache.join("services.php"))),
        ("APP_PACKAGES_CACHE", s(&cache.join("packages.php"))),
        ("APP_CONFIG_CACHE", s(&cache.join("config.php"))),
        ("APP_ROUTES_CACHE", s(&cache.join("routes-v7.php"))),
        ("APP_EVENTS_CACHE", s(&cache.join("events.php"))),
        ("VIEW_COMPILED_PATH", s(&paths.storage().join("framework/views"))),
        ("KURS_PUBLIC_PATH", s(&paths.public())),
        ("KURS_NODE", "local".into()),
        ("KURS_DESKTOP", "1".into()),
        ("KURS_DESKTOP_VERSION", env!("CARGO_PKG_VERSION").into()),
        ("DB_CONNECTION", "sqlite".into()),
        ("DB_DATABASE", s(&paths.db_file())),
    ];
    for (k, v) in fixed {
        env.insert(k.into(), v);
    }

    // Sırlar (anahtar zincirinden)
    env.insert("APP_KEY".into(), secrets.app_key.clone());
    match &secrets.device_token {
        Some(t) => env.insert("SYNC_DEVICE_TOKEN".into(), t.clone()),
        None => env.remove("SYNC_DEVICE_TOKEN"),
    };
    match &secrets.data_key {
        Some(k) => env.insert("KURS_DATA_KEY".into(), k.clone()),
        None => env.remove("KURS_DATA_KEY"),
    };

    for (k, v) in extra {
        env.insert((*k).to_string(), v.clone());
    }
    env.into_iter().collect()
}

pub fn base_command(paths: &Paths, env: &[(String, String)]) -> Command {
    let mut cmd = Command::new(paths.php());
    cmd.env_clear();
    cmd.envs(env.iter().map(|(k, v)| (k.as_str(), v.as_str())));
    cmd.current_dir(paths.laravel());
    cmd.kill_on_drop(true);
    #[cfg(unix)]
    cmd.process_group(0);
    cmd
}

#[derive(Debug)]
pub struct ArtisanOutput {
    pub ok: bool,
    pub code: Option<i32>,
    pub stdout: String,
    pub stderr: String,
}

impl ArtisanOutput {
    /// Kullanıcıya gösterilecek kısa hata ayrıntısı (son satırlar).
    pub fn tail(&self) -> String {
        let text = if self.stderr.trim().is_empty() { &self.stdout } else { &self.stderr };
        let lines: Vec<&str> = text.lines().filter(|l| !l.trim().is_empty()).collect();
        lines[lines.len().saturating_sub(6)..].join("\n")
    }
}

/// artisan komutu çalıştırır; `stdin` verilirse yazıp kapatır.
pub async fn artisan(
    paths: &Paths,
    env: &[(String, String)],
    args: &[&str],
    stdin: Option<String>,
    timeout: Duration,
) -> Result<ArtisanOutput, String> {
    let mut cmd = base_command(paths, env);
    cmd.arg(paths.artisan()).args(args).arg("--no-ansi");
    cmd.stdin(if stdin.is_some() { Stdio::piped() } else { Stdio::null() });
    cmd.stdout(Stdio::piped()).stderr(Stdio::piped());
    log::info!("artisan {}", args.join(" "));
    let mut child = cmd.spawn().map_err(|e| format!("PHP başlatılamadı ({}): {e}", paths.php().display()))?;
    if let Some(input) = stdin {
        if let Some(mut pipe) = child.stdin.take() {
            pipe.write_all(input.as_bytes()).await.map_err(|e| e.to_string())?;
            pipe.shutdown().await.ok();
        }
    }
    let out = match tokio::time::timeout(timeout, child.wait_with_output()).await {
        Ok(r) => r.map_err(|e| format!("PHP süreci okunamadı: {e}"))?,
        Err(_) => return Err(format!("Komut zaman aşımına uğradı: artisan {} ({} sn)", args.join(" "), timeout.as_secs())),
    };
    let res = ArtisanOutput {
        ok: out.status.success(),
        code: out.status.code(),
        stdout: String::from_utf8_lossy(&out.stdout).into_owned(),
        stderr: String::from_utf8_lossy(&out.stderr).into_owned(),
    };
    if !res.ok {
        log::warn!("artisan {} başarısız (kod {:?}): {}", args.join(" "), res.code, res.tail());
    }
    Ok(res)
}

/// Satır satır JSON olay veren makine komutları (kurs:desktop-pair, kurs:desktop-snapshot).
/// Her JSON satırı `on_event`'e verilir; JSON olmayan satırlar günlüğe yazılır.
pub async fn artisan_events<F>(
    paths: &Paths,
    env: &[(String, String)],
    args: &[&str],
    stdin: Option<String>,
    timeout: Duration,
    mut on_event: F,
) -> Result<ArtisanOutput, String>
where
    F: FnMut(serde_json::Value) + Send,
{
    let mut cmd = base_command(paths, env);
    cmd.arg(paths.artisan()).args(args).arg("--no-ansi");
    cmd.stdin(if stdin.is_some() { Stdio::piped() } else { Stdio::null() });
    cmd.stdout(Stdio::piped()).stderr(Stdio::piped());
    log::info!("artisan {} (olaylı)", args.join(" "));
    let mut child = cmd.spawn().map_err(|e| format!("PHP başlatılamadı: {e}"))?;
    if let Some(input) = stdin {
        if let Some(mut pipe) = child.stdin.take() {
            pipe.write_all(input.as_bytes()).await.map_err(|e| e.to_string())?;
            pipe.shutdown().await.ok();
        }
    }
    let stdout = child.stdout.take().ok_or("stdout yok")?;
    let stderr = child.stderr.take().ok_or("stderr yok")?;
    let err_task = tokio::spawn(async move {
        let mut buf = String::new();
        let mut lines = BufReader::new(stderr).lines();
        while let Ok(Some(l)) = lines.next_line().await {
            buf.push_str(&l);
            buf.push('\n');
        }
        buf
    });

    let run = async {
        let mut rest = String::new();
        let mut lines = BufReader::new(stdout).lines();
        while let Ok(Some(line)) = lines.next_line().await {
            match serde_json::from_str::<serde_json::Value>(&line) {
                Ok(v) if v.is_object() => on_event(v),
                _ => {
                    if !line.trim().is_empty() {
                        log::info!("[artisan] {line}");
                        rest.push_str(&line);
                        rest.push('\n');
                    }
                }
            }
        }
        let status = child.wait().await;
        (status, rest)
    };

    let (status, rest) = match tokio::time::timeout(timeout, run).await {
        Ok(v) => v,
        Err(_) => return Err(format!("Komut zaman aşımına uğradı: artisan {}", args.join(" "))),
    };
    let stderr = err_task.await.unwrap_or_default();
    let status = status.map_err(|e| e.to_string())?;
    Ok(ArtisanOutput { ok: status.success(), code: status.code(), stdout: rest, stderr })
}
