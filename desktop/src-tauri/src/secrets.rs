//! Sırlar işletim sistemi anahtar zincirinde (macOS Keychain, ileride Windows Credential Manager).
//! Hiçbiri diske düz yazılmaz; PHP süreçlerine yalnız başlatılırken ortam değişkeni olarak verilir:
//!   app-key       → APP_KEY          (yerelde üretilir; oturum/çerez imzası + yerel şifreleme)
//!   device-token  → SYNC_DEVICE_TOKEN (eşleştirmede sunucudan; yalnız 'sync' yeteneği)
//!   data-key      → KURS_DATA_KEY     (kurum veri anahtarı; mühürlü paketle gelir, yoksa boş)

use base64::Engine;
use keyring::Entry;

pub const SERVICE: &str = "tr.com.erbaabilgi.kurs";
pub const APP_KEY: &str = "app-key";
pub const DEVICE_TOKEN: &str = "device-token";
pub const DATA_KEY: &str = "data-key";

fn entry(account: &str) -> Result<Entry, String> {
    Entry::new(SERVICE, account).map_err(|e| format!("Anahtar zincirine erişilemedi: {e}"))
}

pub fn get(account: &str) -> Result<Option<String>, String> {
    match entry(account)?.get_password() {
        Ok(v) if !v.is_empty() => Ok(Some(v)),
        Ok(_) => Ok(None),
        Err(keyring::Error::NoEntry) => Ok(None),
        Err(e) => Err(format!(
            "Anahtar zinciri kaydı okunamadı ({account}): {e}. macOS erişim isteğine \"İzin Ver\" deyin ya da Anahtar Zinciri Erişimi'nde \"{SERVICE}\" kaydını denetleyin."
        )),
    }
}

pub fn set(account: &str, value: &str) -> Result<(), String> {
    entry(account)?
        .set_password(value)
        .map_err(|e| format!("Anahtar zincirine yazılamadı ({account}): {e}"))
}

pub fn delete(account: &str) -> Result<(), String> {
    match entry(account)?.delete_credential() {
        Ok(()) | Err(keyring::Error::NoEntry) => Ok(()),
        Err(e) => Err(format!("Anahtar zinciri kaydı silinemedi ({account}): {e}")),
    }
}

pub fn random_bytes(n: usize) -> Result<Vec<u8>, String> {
    let mut buf = vec![0u8; n];
    getrandom::fill(&mut buf).map_err(|e| format!("Rastgele sayı üretilemedi: {e}"))?;
    Ok(buf)
}

/// Laravel APP_KEY biçimi: "base64:" + 32 bayt (AES-256-CBC).
pub fn ensure_app_key() -> Result<String, String> {
    if let Some(k) = get(APP_KEY)? {
        return Ok(k);
    }
    let key = format!("base64:{}", base64::engine::general_purpose::STANDARD.encode(random_bytes(32)?));
    set(APP_KEY, &key)?;
    Ok(key)
}

/// Yerel sunucu jetonu: her açılışta yeni, yalnız bellekte.
pub fn new_session_token() -> Result<String, String> {
    Ok(hex::encode(random_bytes(32)?))
}

pub fn sha256_hex(value: &str) -> String {
    use sha2::{Digest, Sha256};
    hex::encode(Sha256::digest(value.as_bytes()))
}

pub struct LocalSecrets {
    pub app_key: String,
    pub device_token: Option<String>,
    pub data_key: Option<String>,
}

pub fn load_local() -> Result<LocalSecrets, String> {
    let app_key = get(APP_KEY)?.ok_or_else(|| {
        "Yerel kurulumun şifreleme anahtarı anahtar zincirinde bulunamadı. Kurulumu sıfırlayıp yeniden eşleştirin.".to_string()
    })?;
    Ok(LocalSecrets { app_key, device_token: get(DEVICE_TOKEN)?, data_key: get(DATA_KEY)? })
}

pub fn wipe_local() -> Result<(), String> {
    delete(DEVICE_TOKEN)?;
    delete(DATA_KEY)?;
    delete(APP_KEY)?;
    Ok(())
}
