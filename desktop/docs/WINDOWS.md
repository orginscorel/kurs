# Windows masaüstü sürümü

macOS akışının (bkz. `DESKTOP.md`) Windows kardeşi. Aynı mimari: uygulama, gömülü **PHP** ile
**tüm Laravel'i cihazda yerel** çalıştırır (çevrimdışı yerel kurulum, web ile eşitlenir). CI: `.github/workflows/desktop-windows.yml`.

**PHP kaynağı (Windows'a özel):** macOS statik-PHP (SPC) kullanır; SPC Windows'ta `php-src` çıkarma adımında
kararsız olduğundan Windows tarafı **windows.php.net resmi PHP (NTS x64)** binary'sini kullanır. Bu tek statik dosya
değildir: `php.exe` + `php8.dll` + ICU DLL'leri + `ext\php_*.dll` bir **klasör** olarak (`php-dist`) uygulama
kaynaklarına (`resources/php-dist`) paketlenir. Uzantılar `conf.d\ext.ini` içinden `PHP_INI_SCAN_DIR` ile yüklenir
(`extension_dir = ${KURS_PHP_EXT_DIR}` çalışma zamanında `src/php.rs` tarafından verilir). Paylaşılan `runtime/php.ini`
(PHPRC) her iki platformda aynıdır. Hazırlama betiği: `scripts/build-static-php-win.ps1` (`PHP_MINOR`, vars. 8.4).

## Nasıl derlenir / yayınlanır

- **Etiketle (otomatik):** `desktop-win-v<sürüm>` etiketi it (ör. `desktop-win-v1.22.4`). CI derler ve aynı adlı bir
  GitHub Release'e **NSIS kurulumu (`*-setup.exe`)** ve **MSI** yükler. (macOS'un `desktop-v*` etiketinden ayrıdır; ikisi çakışmaz.)
- **Elle:** GitHub → Actions → **desktop-windows** → Run workflow (isteğe bağlı: *publish* = Release oluştur).

Sürüm numarası `package.json`/`tauri.conf.json`/`Cargo.toml`/`CHANGELOG` ile tutarlı olmalı (`scripts/release.mjs check` CI'da doğrular).

## Çıktı

- `…\bundle\nsis\Erbaa Kurs_<sürüm>_x64-setup.exe` — kurulum sihirbazı (önerilen).
- `…\bundle\msi\Erbaa Kurs_<sürüm>_x64_en-US.msi` — kurumsal dağıtım için.

## İmzalama (şimdilik YOK)

İlk sürüm **imzasız**dır. Windows SmartScreen "Bilinmeyen yayımcı" uyarısı gösterebilir →
**"Daha fazla bilgi" › "Yine de çalıştır"**. Kod imzası eklemek için bir Authenticode sertifikası (OV/EV) gerekir;
sertifika + parola GitHub Secrets'a konup `tauri build`'e imza adımı eklenerek uyarı kaldırılabilir (sonraki iterasyon).

## Otomatik güncelleme

Windows derlemesinde `createUpdaterArtifacts=false` (bkz. `src-tauri/tauri.windows.conf.json`) — ilk sürümde
**oto-güncelleme kapalı**; kullanıcı yeni kurulumu indirir. Açmak için: updater imza anahtarı (`TAURI_SIGNING_PRIVATE_KEY`)
+ Windows için ayrı bir `latest.json` uç noktası gerekir (macOS'unkinden ayrı).

## Bilinen sınırlar / riskler

- **php -S tek işçi:** Windows'ta PHP yerleşik sunucusu `PHP_CLI_SERVER_WORKERS`'ı yok sayar (fork yok) → aynı anda tek
  istek işlenir. Tek kullanıcılı masaüstü için yeterli; ağır eşzamanlılıkta yavaş olabilir.
- **Uzantı farkı:** Windows statik PHP setinden `pcntl` ve `posix` çıkarıldı (Unix'e özel). Gerisi macOS ile aynı.
- **Süreç sonlandırma:** Rust tarafındaki süreç-grubu sonlandırma (SIGTERM/SIGKILL) `#[cfg(unix)]` ile korumalı; Windows'ta
  alt PHP süreçlerinin temiz kapanışı için ayrı bir uygulama (job object / taskkill) sonradan eklenmeli. Derlemeyi engellemez.
- **İlk CI koşusu:** Bu akış yerelde test EDİLEMEDİ (Windows runner gerekir). İlk çalıştırmada SPC uzantı derlemesi,
  Rust'ın Windows'ta derlenmesi veya installer yolları küçük düzeltmeler isteyebilir — CI loglarına göre ayarlanır.
