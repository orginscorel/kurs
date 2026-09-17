//! Uygulama yolları.
//!
//! macOS:
//!   veri     ~/Library/Application Support/tr.com.erbaabilgi.kurs/
//!              settings.json            kip, sunucu adresi, kurulum durumu (sır YOK)
//!              local/.env               yerel Laravel yapılandırması (sır YOK)
//!              local/database/kurs-local.sqlite
//!              local/storage/…          Laravel storage (oturum, önbellek, yüklenen dosyalar, laravel.log)
//!              local/cache/*.php        Laravel paket/servis/rota önbelleği
//!              backups/                 güncelleme ve sıfırlama öncesi SQLite kopyaları
//!   günlük   ~/Library/Logs/tr.com.erbaabilgi.kurs/  (uygulama + php-server.log + php-error.log)
//!   paket    Erbaa Kurs.app/Contents/MacOS/php (gömülü PHP), Contents/Resources/laravel, …/runtime

use std::path::{Path, PathBuf};
use tauri::{AppHandle, Manager, Runtime};

#[derive(Clone, Debug)]
pub struct Paths {
    pub data: PathBuf,
    pub logs: PathBuf,
    pub resources: PathBuf,
    pub exe_dir: PathBuf,
}

impl Paths {
    pub fn resolve<R: Runtime>(app: &AppHandle<R>) -> Result<Self, String> {
        let p = app.path();
        let data = p.app_data_dir().map_err(|e| format!("Uygulama veri klasörü bulunamadı: {e}"))?;
        let logs = p.app_log_dir().map_err(|e| format!("Günlük klasörü bulunamadı: {e}"))?;
        let resources = p.resource_dir().map_err(|e| format!("Paket kaynak klasörü bulunamadı: {e}"))?;
        let exe_dir = std::env::current_exe()
            .ok()
            .and_then(|e| e.parent().map(Path::to_path_buf))
            .ok_or_else(|| "Uygulama klasörü bulunamadı.".to_string())?;
        Ok(Self { data, logs, resources, exe_dir })
    }

    pub fn settings_file(&self) -> PathBuf {
        self.data.join("settings.json")
    }
    pub fn local_root(&self) -> PathBuf {
        self.data.join("local")
    }
    pub fn env_file(&self) -> PathBuf {
        self.local_root().join(".env")
    }
    pub fn database_dir(&self) -> PathBuf {
        self.local_root().join("database")
    }
    pub fn db_file(&self) -> PathBuf {
        self.database_dir().join("kurs-local.sqlite")
    }
    pub fn storage(&self) -> PathBuf {
        self.local_root().join("storage")
    }
    pub fn cache_dir(&self) -> PathBuf {
        self.local_root().join("cache")
    }
    pub fn backups(&self) -> PathBuf {
        self.data.join("backups")
    }

    /// Paket içindeki Laravel kopyası (salt okunur).
    pub fn laravel(&self) -> PathBuf {
        self.resources.join("laravel")
    }
    pub fn public(&self) -> PathBuf {
        self.laravel().join("public")
    }
    pub fn artisan(&self) -> PathBuf {
        self.laravel().join("artisan")
    }
    pub fn runtime_dir(&self) -> PathBuf {
        self.resources.join("runtime")
    }
    pub fn router(&self) -> PathBuf {
        self.runtime_dir().join("router.php")
    }
    pub fn prepend(&self) -> PathBuf {
        self.runtime_dir().join("prepend.php")
    }
    pub fn cacert(&self) -> PathBuf {
        self.runtime_dir().join("cacert.pem")
    }
    pub fn env_template(&self) -> PathBuf {
        self.runtime_dir().join("env.template")
    }

    /// Gömülü PHP (tauri externalBin: Contents/MacOS/php).
    pub fn php(&self) -> PathBuf {
        let name = if cfg!(windows) { "php.exe" } else { "php" };
        self.exe_dir.join(name)
    }

    pub fn php_server_log(&self) -> PathBuf {
        self.logs.join("php-server.log")
    }
    pub fn php_error_log(&self) -> PathBuf {
        self.logs.join("php-error.log")
    }
    pub fn scheduler_log(&self) -> PathBuf {
        self.logs.join("scheduler.log")
    }

    /// Yerel kip için paket eksiksiz mi (yalnız çevrimiçi paket üretilirse false).
    pub fn runtime_available(&self) -> bool {
        self.php().is_file() && self.artisan().is_file() && self.router().is_file()
    }

    pub fn ensure_local_dirs(&self) -> Result<(), String> {
        let storage = self.storage();
        let dirs = [
            self.logs.clone(),
            self.database_dir(),
            self.cache_dir(),
            self.backups(),
            storage.join("app/private"),
            storage.join("app/public"),
            storage.join("framework/cache/data"),
            storage.join("framework/sessions"),
            storage.join("framework/views"),
            storage.join("framework/testing"),
            storage.join("logs"),
            // dompdf yazı tipi ölçü önbelleği (config/dompdf.php › font_dir = storage_path('fonts'))
            storage.join("fonts"),
        ];
        for d in dirs {
            std::fs::create_dir_all(&d).map_err(|e| format!("Klasör oluşturulamadı ({}): {e}", d.display()))?;
        }
        // Laravel SQLite bağlantısı dosyanın var olmasını ister (migrate etkileşimsiz kipte oluşturmaz)
        let db = self.db_file();
        if !db.exists() {
            std::fs::File::create(&db).map_err(|e| format!("Veritabanı dosyası oluşturulamadı: {e}"))?;
        }
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            // Yerel veri yalnız bu kullanıcıya açık
            let _ = std::fs::set_permissions(&self.data, std::fs::Permissions::from_mode(0o700));
        }
        Ok(())
    }
}
