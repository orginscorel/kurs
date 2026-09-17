//! Kalıcı uygulama ayarları (settings.json). Sır içermez.

use serde::{Deserialize, Serialize};
use std::path::Path;

#[derive(Clone, Debug, Default, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "lowercase")]
pub enum Mode {
    #[default]
    Unset,
    Local,
    Remote,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct SetupState {
    #[serde(default)]
    pub env_ready: bool,
    #[serde(default)]
    pub paired: bool,
    #[serde(default)]
    pub snapshot_done: bool,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct Settings {
    #[serde(default)]
    pub mode: Mode,
    #[serde(default)]
    pub server_url: Option<String>,
    #[serde(default)]
    pub device_name: Option<String>,
    #[serde(default)]
    pub device_code: Option<String>,
    #[serde(default)]
    pub setup: SetupState,
    /// Yerel veritabanının en son hangi uygulama sürümüyle göç ettirildiği (sürüm değişince yedek alınır).
    #[serde(default)]
    pub last_bundle_version: Option<String>,
    /// "Daha sonra" denen güncelleme hatırlatmasının bitiş zamanı (unix sn)
    #[serde(default)]
    pub update_snoozed_until: Option<u64>,
    #[serde(default)]
    pub snoozed_version: Option<String>,
}

impl Settings {
    pub fn load(path: &Path) -> Self {
        match std::fs::read_to_string(path) {
            Ok(raw) => serde_json::from_str(&raw).unwrap_or_else(|e| {
                log::warn!("settings.json okunamadı, varsayılan kullanılıyor: {e}");
                Settings::default()
            }),
            Err(_) => Settings::default(),
        }
    }

    /// Atomik yazma (yarım dosya kalmasın).
    pub fn save(&self, path: &Path) -> Result<(), String> {
        if let Some(dir) = path.parent() {
            std::fs::create_dir_all(dir).map_err(|e| format!("Ayar klasörü oluşturulamadı: {e}"))?;
        }
        let tmp = path.with_extension("json.tmp");
        let raw = serde_json::to_string_pretty(self).map_err(|e| e.to_string())?;
        std::fs::write(&tmp, raw).map_err(|e| format!("Ayarlar yazılamadı: {e}"))?;
        std::fs::rename(&tmp, path).map_err(|e| format!("Ayarlar kaydedilemedi: {e}"))?;
        Ok(())
    }

    pub fn local_ready(&self) -> bool {
        self.mode == Mode::Local && self.setup.env_ready && self.setup.paired && self.setup.snapshot_done
    }
}
