# Erbaa Kurs — Masaüstü uygulaması (macOS)

Durum: **proje hazır, ilk CI derlemesi bekleniyor (2026-09-17).** Rust kodu sunucuda kullanıcı düzeyi araç zinciriyle `cargo check`/`clippy --target aarch64-apple-darwin` (sahte C derleyicisiyle) uyarısız geçti; `src-tauri/Cargo.lock` depodadır (CI `--locked`). Bu sunucuda Rust/Xcode yok; derleme, imzalama ve
notarization tamamen GitHub Actions'ta yapılır. Windows, iOS ve Android sonraki aşamadır (bkz. §11).

İçindekiler: 1 Mimari · 2 Kip akışları · 3 Güvenlik · 4 PHP sunucusu · 5 Proje yapısı · 6 Derleme hattı (CI) ·
7 Güncelleme ve sürüm · 8 Sizin yapmanız gerekenler · 9 Sürüm çıkarma · 10 Sorun giderme · 11 Sonraki platformlar

---

## 1. Mimari

```
 Erbaa Kurs.app (Tauri 2, Rust)                                     Kurum sunucusu (web)
 ┌──────────────────────────────────────────────────────────┐      ┌───────────────────────────────┐
 │ Pencere (WKWebView)                                       │      │ https://kurs.bogahostdeveloper│
 │   • kurulum ön yüzü (ui/, tauri://localhost)              │      │  .com.tr                      │
 │   • Kip A: http://127.0.0.1:<rastgele port>  ─────┐       │      │                               │
 │   • Kip B: https://kurs… (doğrudan) ──────────────┼───────┼─────▶│ Laravel + React (aynı kod)    │
 │ Köprü betiği (bildirim, Dock rozeti)              │       │      │ /api/v1/sync/*  (eşitleme)    │
 │ Menü, tepsi, tek örnek, derin bağlantı            │       │      │ /desktop/latest.json (güncel.)│
 │ Güncelleyici (imzalı)                              │       │      └───────────────▲───────────────┘
 │ Anahtar Zinciri: APP_KEY, cihaz jetonu, veri anahtarı│    │                      │ eşitleme (HTTPS)
 │                                                   ▼       │                      │
 │  Contents/MacOS/php  (statik PHP 8.4)  ── php -S + router.php (Kip A)            │
 │  Contents/Resources/laravel  (salt okunur kopya) ── artisan schedule:run (dk) ───┘
 └──────────────────────────────────────────────────────────┘
   ~/Library/Application Support/tr.com.erbaabilgi.kurs/  settings.json, local/.env, local/database/*.sqlite,
                                                          local/storage/*, local/cache/*, backups/
   ~/Library/Logs/tr.com.erbaabilgi.kurs/                 uygulama.log, php-server.log, php-error.log, scheduler.log
```

* **Kabuk:** Tauri 2 (Rust). Eklentiler: updater, notification, single-instance (+deep-link), deep-link (`erbaakurs://`),
  autostart (LaunchAgent), log, opener. Sırlar `keyring` (macOS Keychain) ile.
* **Yerel çalışma zamanı (Kip A):** static-php-cli ile derlenmiş tek dosya PHP 8.4 (universal: arm64 + x86_64) +
  paketteki Laravel kopyası (vendor `--no-dev`, derlenmiş ön yüz) + SQLite. Laravel'in yazdığı her şey uygulama veri
  klasörüne yönlendirilir (`LARAVEL_STORAGE_PATH`, `APP_*_CACHE`); paket salt okunur kalır ve imza bozulmaz.
* **Tek kaynak kod:** masaüstünde çalışan Laravel, web ile birebir aynı koddur; `KURS_NODE=local` ile yerel düğüm
  davranışına geçer (docs/SYNC.md: SQLite uyumluluğu, dış istek yasağı, yalnız eşitleme zamanlayıcısı).

## 2. Kip akışları

### Kip A — Kurum bilgisayarı (yerel kurulum)

İlk açılış (kurulum ön yüzü → `setup_local`):

1. **Hazırlık:** veri klasörü (0700), `APP_KEY` üretilir → Anahtar Zinciri; `local/.env` şablondan yazılır
   (`KURS_NODE=local`, SQLite yolu, `SYNC_SERVER_URL`; **sır yok**), boş SQLite dosyası.
2. **Veritabanı:** `php artisan migrate --force` (tüm göçler SQLite uyumlu).
3. **Eşleştirme:** `php artisan kurs:desktop-pair` — girdi STDIN'den JSON (parola komut satırında görünmez).
   Çıktıdaki cihaz jetonu ve kurum veri anahtarı Anahtar Zinciri'ne yazılır; komut bunları yerel veritabanından siler.
4. **İlk eşitleme:** `php artisan kurs:desktop-snapshot` satır satır JSON ilerleme verir → ekranda yüzde çubuğu.
   Kopma olursa "Yeniden dene" kaldığı yerden (yeniden eşleştirmeden) sürdürür.
5. **Başlatma:** rastgele port (yalnız `127.0.0.1`), tek kullanımlık jeton, `php -S … router.php`,
   dakikada bir `schedule:run`, pencere `http://127.0.0.1:<port>/__desktop/boot?t=<jeton>` adresine gider
   (çerez yazılır, `/`'a yönlenir). "Oturum açılınca başlat" açılır (menüden kapatılabilir).

Sonraki açılışlar ("Başlatılıyor" ekranı → `start_local`): sürüm değiştiyse SQLite yedeği + önbellek temizliği,
`migrate --force`, (sürüm değiştiyse) `route:cache` + `event:cache`, sunucu + zamanlayıcı, pencere yönlendirmesi.

* Pencereyi kapatmak uygulamayı kapatmaz (eşitleme arka planda sürer); Dock/tepsi simgesi pencereyi geri açar.
  Çıkış (⌘Q, tepsi › Çıkış): önce süreçler düzgün durdurulur (SIGTERM → 5 sn → SIGKILL, süreç grupları).
* Çökme gözetimi: PHP sunucusu kapanırsa aynı portta, artan beklemeyle yeniden başlatılır; 5 dakikada 5'ten fazla
  kapanırsa hata ekranı açılır.
* Menü › Eşitleme › "Şimdi eşitle" (`kurs:sync --force`), "Kullanım biçimi ve kurulum…" (sıfırlama).
* Kurulumu sıfırlama: gönderilmemiş/reddedilmiş değişiklik varsa uyarır; zorlanırsa önce SQLite yedeği alınır,
  sonra yerel veri + anahtarlar silinir. Cihaz web'de "Bağlı cihazlar" listesinde kalır (oradan iptal edilir).

### Kip B — Çevrimiçi kullanım (öğretmen / personel)

1. Sunucu adresi girilir → `GET /api/v1/sync/ping` ile doğrulanır (`app: kurs`).
2. Pencere doğrudan kurum sunucusunu açar; giriş web ile aynıdır (öğretmen portalı `/ogretmen`, yönetim `/`).
3. Köprü betiği yalnız izinli kökende çalışır: `window.Notification` → macOS bildirimi; dakikada bir
   `/api/v1/notifications?filter=unread` yoklanır, yeni gelenler (en fazla 3) bildirim olarak gösterilir, okunmamış
   sayısı Dock rozetine yazılır. `window.__KURS_DESKTOP__ = {version, mode}` (web'deki /uygulamalar bunu gösterir).
4. Açılışta sunucuya ulaşılamazsa hata ekranı ("Yeniden dene", "Kurulum seçenekleri").

Ortak: dış bağlantılar (wa.me, tel:, mailto:, başka siteler) sistem tarayıcısında açılır; indirmeler (PDF/Excel)
İndirilenler klasörüne kaydedilir ve bildirim verilir; `erbaakurs://ac?yol=/ogrenciler/12` derin bağlantısı
uygulamayı öne getirip o sayfayı açar.

## 3. Güvenlik kararları

| Konu | Karar |
|---|---|
| Yerel sunucu erişimi | Yalnız `127.0.0.1`'e bağlanır. **İki katman:** paketteki `runtime/router.php` + web uygulamasındaki `App\Http\Middleware\DesktopLocalGuard` (yalnız `KURS_NODE=local` ve `KURS_DESKTOP_TOKEN_HASH` varken). Host başlığı 127.0.0.1/localhost olmalı (DNS yeniden bağlama → 421). Her istek (statik dosyalar ve `/storage` dahil) açılışta üretilen 256 bit jetonu taşımalı: `kurs_desktop` çerezi (HttpOnly, SameSite=Strict) ya da `X-Kurs-Desktop` başlığı; yoksa 403. |
| Jeton saklama | Jeton yalnız uygulama belleğinde ve pencere çerezinde; PHP ortamında yalnız SHA-256 özeti var. Her açılışta yenilenir. Aynı makinedeki başka bir süreç ya da tarayıcıdaki bir web sayfası jetonu bilmediği için yerel sunucuyu kullanamaz. (Aynı kullanıcıyla çalışan kötü amaçlı bir yazılıma karşı tam koruma işletim sistemi düzeyinde mümkün değildir; veri klasörü 0700.) |
| Sırlar | `APP_KEY`, cihaz jetonu (`SYNC_DEVICE_TOKEN`), kurum veri anahtarı (`KURS_DATA_KEY`) macOS Anahtar Zinciri'nde (`tr.com.erbaabilgi.kurs`). `.env` ve `settings.json` sır içermez; sırlar yalnız PHP süreci başlatılırken ortam değişkeni olarak verilir. Laravel yapılandırması **önbelleğe alınmaz** (`config:cache` sırları diske yazardı). Anahtar Zinciri kaydının erişim listesi uygulama imzasına bağlıdır; aynı Team ID ile imzalı güncellemeler soru sormadan erişir. |
| Parola | Eşleştirme parolası kaydedilmez, `kurs:desktop-pair`'e STDIN ile verilir (süreç listesinde görünmez). |
| PHP süreçleri | `env_clear()` + beyaz liste ortam; `PHPRC` paketteki `php.ini` (JIT kapalı, `zlib.output_compression=Off`), `PHP_INI_SCAN_DIR` boş (kullanıcının Homebrew PHP ayarları karışmaz). Statik PHP sistem sertifika deposunu görmez → paketle gelen Mozilla CA listesi (`curl.cainfo`/`openssl.cafile`). |
| Uygulama yetenekleri (IPC) | `build.rs` uygulama komutlarını listeler; `capabilities/setup.json` yalnız uygulamanın kendi sayfalarına (tauri://), `capabilities/web-bridge.json` kurum sunucusu ve 127.0.0.1'e **yalnız** `desktop_info`, `desktop_notify`, `desktop_badge` verir. Başka bir sunucu adresi seçilirse aynı üç izin çalışma anında o köken için eklenir. |
| Gezinme | Ana pencere yalnız izinli kökenlere gider; diğer adresler sistemde açılır. |
| İmza | Developer ID Application + sertleştirilmiş çalışma zamanı (hardened runtime); gömülü `php` ayrıca imzalanır; JIT/imzasız bellek yetkisi YOK; DMG imzalı + notarize + zımbalı. Güncelleme paketleri Tauri (minisign) anahtarıyla imzalı; uygulama imzasız güncellemeyi kurmaz. |
| Kurum verisi | TC gibi şifreli alanlar sunucudaki `KURS_DATA_KEY` ile şifreli (2026-09-17'de üretildi ve taşındı); yerel kurulum anahtarı eşleştirmede mühürlü paketle alır ve Anahtar Zinciri'ne yazar. |
| Paket içeriği | `bundle-laravel.sh` `.env*`, yedek, günlük, `storage` içeriği, test, gateway, anahtar dosyalarını dışlar ve pakette sır taraması yapar. Paket web uygulamasının PHP kaynak kodunu içerir (sır yok); indirme adresleri herkese açıktır. |

## 4. PHP sunucusu seçimi: `php -S` (+ PHP_CLI_SERVER_WORKERS=4)

| | `php -S` + router | FrankenPHP |
|---|---|---|
| İkili | static-php-cli'nin olgun `--build-cli` çıktısı (~40–60 MB/mimari) | Go + xcaddy + libphp; ~2 kat büyük, derleme daha kırılgan |
| Eşzamanlılık | `PHP_CLI_SERVER_WORKERS=4` ile 4 süreç (tek kullanıcı + SQLite tek yazıcı için yeterli) | İş parçacığı/işçi kipi, çok daha yüksek |
| Hız | Her istek Laravel'i yeniden başlatır; OPcache açık (sunucuda ölçüm: `/up` ≈ 0,27 sn soğuk, sayfa gezintisi ≈ 1,7 sn ağ boşta dahil) | İşçi kipinde çok daha hızlı, ama Laravel'in işçi kipine hazır olması (durum sızıntısı) ayrıca doğrulanmalı |
| İmza/notarization | Tek ikili | Ek dinamik bileşen yok ama daha büyük yüzey |
| Risk | PHP belgesi "geliştirme sunucusu" der; burada yalnız 127.0.0.1 + jetonla tek kullanıcı | Yeni yığın, CI süresi uzun |

**Karar:** ilk sürümde `php -S`. Yerel kullanımda darboğaz ağ değil SQLite ve Laravel açılışı. Ölçüm gerekirse
FrankenPHP'ye geçiş `runtime.rs › spawn_server` ile sınırlıdır (DesktopLocalGuard FrankenPHP'de de çalışır;
`/__desktop/boot`'u Laravel de karşılar).

## 5. Proje yapısı

```
desktop/                              (sunucuda /home/oritoriu/kurs-desktop)
├── package.json, tsconfig.json, vite.config.ts, index.html, update.html
├── CHANGELOG.json                    masaüstü sürüm günlüğü (web'den bağımsız semver)
├── ui/                               kurulum ön yüzü (React 19, bağımlılıksız bileşenler, Inter, lacivert #1f3b63)
│   ├── api.ts                        Rust komut/olay sözleşmesi (+ tarayıcı önizlemesi için sahte arka uç)
│   ├── App.tsx, main.tsx             kip seçimi → sunucu → eşleştirme → kurulum → başlatma / hata
│   ├── UpdateApp.tsx, update-main.tsx, changelog.ts   "Yeni sürüm yayında" penceresi
│   ├── components.tsx, styles.css
│   └── screens/ ModeScreen, ServerScreen, AccountScreen, InstallScreen, StartingScreen, ErrorScreen
├── runtime/                          pakete girer (Contents/Resources/runtime)
│   ├── router.php                    php -S yönlendiricisi (jeton + host denetimi, statik, /storage)
│   ├── prepend.php                   KURS_PUBLIC_PATH sabiti, $_ENV yolları
│   ├── php.ini                       PHPRC; ${KURS_…} ile yollar
│   └── env.template                  local/.env şablonu (sırsız)
├── src-tauri/
│   ├── Cargo.toml, build.rs, tauri.conf.json, Entitlements.plist
│   ├── capabilities/ setup.json, web-bridge.json
│   ├── icons/                        `npm run icons` ile assets/app-icon.png'den
│   ├── binaries/                     CI: php-{aarch64,x86_64,universal}-apple-darwin
│   └── src/
│       ├── lib.rs                    kurulum, eklentiler, olay döngüsü, derin bağlantı, AppCtx
│       ├── commands.rs               ön yüz komutları + köprü komutları
│       ├── setup_flow.rs             Kip A ilk kurulum / sürdürme / başlatma
│       ├── runtime.rs                php -S, zamanlayıcı, çökme gözetimi, yedek, durdurma
│       ├── php.rs                    ortam, .env, artisan çalıştırma (satır satır JSON olaylar)
│       ├── secrets.rs                Anahtar Zinciri
│       ├── window.rs                 pencere, gezinme koruması, indirmeler, Kip B, yedek güncelleme penceresi
│       ├── updater.rs                güncelleme denetimi/kurulumu (şeride olay, yedekte ayrı pencere)
│       ├── menu.rs                   menü çubuğu + tepsi
│       ├── settings.rs, paths.rs
│       └── bridge.js                 web sayfalarına eklenen köprü + sayfa üstü güncelleme şeridi (shadow DOM)
├── scripts/
│   ├── build-static-php.sh           static-php-cli 2.8.5 ile PHP 8.4 (CI, macOS)
│   ├── bundle-laravel.sh             web uygulamasının üretim kopyası → build/laravel
│   ├── make-latest-json.mjs          güncelleyici bildirimi
│   ├── release.mjs                   sürüm + CHANGELOG (+ check/sync)
│   ├── local-smoke.sh                paket düzenini Linux'ta yerel kipte deneme
│   └── assemble-repo.sh              tek depo çalışma ağacı (kök web + desktop/)
├── docs/DESKTOP.md                   bu belge
├── .github/workflows/desktop-macos.yml   (depoda kökte .github/workflows/ altına kopyalanır)
└── repo-gitignore                    depo kökü .gitignore taslağı
```

Web uygulamasına eklenenler (hepsi yeni dosya; tek ortak dosya `bootstrap/providers.php`'ye bir satır):
`app/Http/Middleware/DesktopLocalGuard.php`, `app/Providers/DesktopServiceProvider.php`, `config/desktop.php`,
`app/Console/Commands/Desktop/{DesktopPair,DesktopSnapshot,DesktopReleaseSync}.php`, `routes/schedules/desktop.php`,
`resources/js/modules/desktop-apps/{module.tsx,AppsPage.tsx}` (`/uygulamalar`, `/ogretmen/uygulamalar`),
`tests/Unit/DesktopLocalGuardTest.php`.

### Depo seçenekleri (web kodu CI'a nasıl gelir)

* **(a) Tek özel depo — önerilen ve varsayılan:** `orginscorel/kurs`; kökte web uygulaması, `desktop/` altında bu proje,
  `.github/workflows/desktop-macos.yml` kökte. Masaüstü sürümü her zaman web koduyla aynı commit'ten derlenir
  (eşitleme protokolü uyumu garanti), ek sır gerekmez.
* **(b) Sunucudan arşiv:** masaüstü ayrı depodaysa ve web kodu GitHub'a konmayacaksa `vars.KURS_APP_SOURCE=tarball` +
  `secrets.KURS_APP_TARBALL_URL` (süreli, imzalı adres) + `secrets.KURS_APP_TARBALL_SHA256`. Sunucuda arşivi üreten
  uç henüz yok (gerekirse `kurs:desktop-export` komutu yazılır). Dezavantaj: sürümler arası eşleşme elle yönetilir.
* **(c) Ayrı web deposu:** `vars.KURS_APP_SOURCE=remote`, `vars.KURS_APP_REPO`, `secrets.KURS_APP_REPO_TOKEN`.

## 6. Derleme hattı (GitHub Actions)

`desktop-macos.yml` — tetik: `desktop-v*` etiketi ya da elle (`unsigned`, `publish`, `run_web_tests` girdileri).

| İş | Koşucu | Ne yapar |
|---|---|---|
| `check` | macos-14 | sürüm tutarlılığı (`release.mjs check`), ön yüz `tsc` + `vite build`, yer tutucu paketle **`cargo check`** + clippy (uyarı), Cargo.lock'u yapıt olarak verir |
| `php` (×2) | macos-14 (arm64), macos-15-intel (x86_64) | static-php-cli 2.8.5 → PHP 8.4 CLI; uzantılar: bcmath ctype curl dom exif fileinfo filter gd(png/jpeg/webp/freetype) iconv intl mbstring opcache openssl pcntl pdo pdo_sqlite phar posix session simplexml sodium sqlite3 tokenizer xml xmlreader xmlwriter zip zlib; JIT derlemede kapalı; Türkçe Collator, sodium, gd, sqlite doğrulaması; `actions/cache` (anahtar: mimari + spc + php + betik özeti) |
| `laravel` | ubuntu-latest | PHP 8.4 + Node 22; web birim testleri; `bundle-laravel.sh` (composer `--no-dev -o --classmap-authoritative`, `vite build` → `public/build`, sadeleştirme, sır taraması, artisan doğrulaması) |
| `build` | macos-14 | yapıtları yerleştir, `lipo` ile universal PHP, Mozilla CA listesi (özet doğrulamalı), güncelleyici açık anahtarı; **paketlenmiş PHP ile duman testi** (migrate, php -S + router, jetonsuz 403 / jetonlu 200); geçici anahtar zinciri + sertifika; gömülü PHP'yi imzala; `tauri build --target universal-apple-darwin` (imza + notarization + zımba + güncelleyici imzası); DMG imzala → `notarytool submit --wait` → `stapler staple`; `codesign --verify --deep --strict`, `spctl`; `latest.json` + SHA256SUMS; yapıt; etiketliyse GitHub Release |

İmzasız deneme (Apple hesabı hazır değilken): Actions › desktop-macos › Run workflow › `unsigned` ✔ →
ad-hoc imzalı DMG yapıt olarak iner (Gatekeeper uyarır; sağ tık › Aç). Yine de `TAURI_SIGNING_PRIVATE_KEY` ve
`TAURI_UPDATER_PUBKEY` gerekir.

### Gerekli GitHub Secrets (Settings › Secrets and variables › Actions)

| Ad | Tür | İçerik |
|---|---|---|
| `APPLE_CERTIFICATE` | secret | Developer ID Application sertifikası + özel anahtarı, `.p12` dosyasının **base64** hali |
| `APPLE_CERTIFICATE_PASSWORD` | secret | `.p12` dışa aktarırken verdiğiniz parola |
| `APPLE_SIGNING_IDENTITY` | secret | `Developer ID Application: Ad Soyad (TEAMID)` (tam metin) |
| `APPLE_TEAM_ID` | secret | 10 karakterlik Team ID |
| `APPLE_API_KEY_ID` | secret | App Store Connect API anahtar kimliği (Key ID) |
| `APPLE_API_ISSUER` | secret | Issuer ID (UUID) |
| `APPLE_API_KEY` | secret | `AuthKey_XXXX.p8` dosyasının **içeriği** (düz metin ya da base64) |
| `TAURI_SIGNING_PRIVATE_KEY` | secret | `tauri signer generate` ile üretilen özel anahtarın içeriği |
| `TAURI_SIGNING_PRIVATE_KEY_PASSWORD` | secret | o anahtarın parolası |
| `TAURI_UPDATER_PUBKEY` | variable (isteğe bağlı) | açık anahtar `tauri.conf.json › plugins.updater.pubkey` içinde (2026-09-17'de yazıldı); değişken verilirse onu ezer |
| `GITHUB_TOKEN` | otomatik | sürüm yayını (ek işlem gerekmez) |
| `KURS_APP_SOURCE`, `KURS_APP_REPO` | variable | yalnız (b)/(c) seçeneğinde |
| `KURS_APP_TARBALL_URL`, `KURS_APP_TARBALL_SHA256`, `KURS_APP_REPO_TOKEN` | secret | yalnız (b)/(c) seçeneğinde |

Sunucu tarafı (`/home/oritoriu/kurs-app/.env`, elle eklenir): `DESKTOP_GITHUB_TOKEN` (ince ayarlı, yalnız bu depo,
**Contents: Read-only**) ve isteğe bağlı `DESKTOP_GITHUB_REPO=orginscorel/kurs`.

## 7. Otomatik güncelleme ve sürüm

* **Bildirim adresi: `https://kurs.bogahostdeveloper.com.tr/desktop/latest.json`** (tauri.conf.json › plugins.updater).
  GitHub Releases doğrudan kullanılamaz: depo **özel** olduğu için sürüm dosyaları oturumsuz indirilemez (uygulamaya
  GitHub anahtarı gömmek kabul edilemez). Bu yüzden:
  1. CI sürümü GitHub Release'e yükler (`ErbaaKurs_<sürüm>_universal.dmg`, `.app.tar.gz`, `.sig`, `latest.json`, `CHANGELOG.json`).
  2. Sunucu `php artisan kurs:desktop-release-sync` (zamanlayıcıda 15 dakikada bir, token yoksa sessiz) en yeni
     taslak olmayan `desktop-v*` sürümünü salt okur anahtarla indirir, boyut/SHA-256 doğrular, web köküne
     `desktop/<sürüm>/` olarak koyar, `latest.json` adreslerini kendi alan adına çevirir, `release.json` (indirme
     sayfası) ve `changelog.json` yazar, eski sürümlerden 2'sini tutar. Hemen yayın için komutu elle çalıştırın
     (`--dry-run`, `--tag=desktop-v0.2.0`, `--force`).
  3. Uygulama açılıştan 20 sn sonra, saatte bir ve ana pencere odağa geldiğinde (en sık 15 dakikada bir) denetler.
     Yeni sürüm varsa ana pencerenin üstünde ince bir şerit çıkar ("Yeni sürüm 0.1.3 hazır · Daha sonra / Güncelle");
     şerit `src/bridge.js` içinde, sayfanın DOM/CSS'ine karışmayan shadow DOM öğesidir ve `update://available`,
     `update://progress`, `update://none`, `update://dismissed` olaylarıyla sürülür. İndirme yüzdesi aynı şeritte akar.
     "Daha sonra" = aynı sürüm için 24 saat (snooze) — şerit o sürüm için kendiliğinden geri gelmez.
     Şerit 2,5 sn içinde kendini bildirmezse (kurulum/hata ekranı, enjeksiyon engeli) eski ayrı "Güncelleme" penceresi
     yedek yol olarak açılır. Kurulumdan önce yerel sunucu düzgün durdurulur, sonra uygulama yeniden başlar;
     açılışta SQLite yedeği + migrate.
* Güncelleme paketi minisign ile imzalıdır; açık anahtar uygulamaya gömülüdür. **Özel anahtarı kaybederseniz
  mevcut kurulumlar yeni sürümü kabul etmez** (anahtarı güvenli yerde yedekleyin).
* **Sürüm:** masaüstü kendi semver'i ile (`desktop/CHANGELOG.json`, en üst kayıt), web sürümünden bağımsız.
  `node scripts/release.mjs minor|patch "Başlık" "yeni: …"` sürümü `package.json` ve `Cargo.toml`'a da yazar.
* **İndirme sayfası:** web'de `/uygulamalar` (Ayarlar altında menü, komut paleti "Masaüstü uygulamasını indir");
  öğretmen portalı hesapları için `/ogretmen/uygulamalar`. Veri `/desktop/release.json`; sürüm yoksa boş durum.

## 8. Sizin yapmanız gerekenler (adım adım)

### 8.1 GitHub
1. Hesap ve özel depo: `orginscorel/kurs` (açıldı). Varsayılan dal `main`.
2. **Ince ayarlı erişim anahtarı (PAT)** — github.com › Settings › Developer settings › Personal access tokens ›
   Fine-grained tokens › Generate new token:
   * Resource owner: kendi hesabınız · Repository access: **Only select repositories → `orginscorel/kurs`**
   * Repository permissions: **Contents: Read and write**, **Actions: Read and write**, **Secrets: Read and write**,
     (Metadata: Read-only otomatik) · Süre: 90 gün önerilir.
   * Bu anahtar depoya itme ve sırları ekleme içindir. Sunucunun sürüm çekmesi için **ayrı** bir anahtar üretin:
     aynı depo, yalnız **Contents: Read-only** → sunucu `.env`'inde `DESKTOP_GITHUB_TOKEN`.
3. Actions'ın açık olduğundan emin olun (Settings › Actions › General › Allow all actions). Workflow izinleri:
   "Read and write permissions" gerekmez (iş kendi `contents: write` iznini ister).

### 8.2 Apple (Developer Program üyeliği gerekir — yıllık ücretli)
1. **Developer ID Application sertifikası** (developer.apple.com › Certificates, IDs & Profiles › Certificates › +):
   * Mac'te: Anahtar Zinciri Erişimi › Sertifika Yardımcısı › "Bir Sertifika Yetkilisinden Sertifika İste…"
     → e-posta, "Diske kaydedildi" → `.certSigningRequest`.
   * Sitede "Developer ID Application" seç (G2 Sub-CA), CSR'ı yükle, `.cer` indir, çift tıkla (Anahtar Zinciri'ne girer).
   * Anahtar Zinciri Erişimi › Oturum Aç › Sertifikalarım › "Developer ID Application: …" (altındaki özel anahtarla
     birlikte) › sağ tık › **Dışa Aktar** → `.p12`, güçlü parola.
   * `base64 -i DeveloperID.p12 | pbcopy` → `APPLE_CERTIFICATE`; parola → `APPLE_CERTIFICATE_PASSWORD`.
   * `security find-identity -v -p codesigning` çıktısındaki tam ad → `APPLE_SIGNING_IDENTITY`.
2. **Team ID:** developer.apple.com › Account › Membership details → `APPLE_TEAM_ID`.
3. **App Store Connect API anahtarı:** appstoreconnect.apple.com › Kullanıcılar ve Erişim › **Entegrasyonlar** ›
   **Takım Anahtarları** › + → ad "GitHub Notarization", erişim **Developer** → Oluştur.
   * Tablodaki **Key ID** → `APPLE_API_KEY_ID`; tablonun üstündeki **Issuer ID** → `APPLE_API_ISSUER`.
   * **API anahtarını indir** (yalnız bir kez indirilebilir) → `AuthKey_XXXX.p8` içeriği → `APPLE_API_KEY`.
4. **Bundle ID:** `tr.com.erbaabilgi.kurs` (tauri.conf.json › identifier). Developer ID dağıtımında App ID kaydı
   zorunlu değildir; alan adınız farklıysa değiştirin (sonradan değiştirmek Anahtar Zinciri kayıtlarını ve veri
   klasörünü değiştirir — ilk yayından önce karar verin).

### 8.3 Güncelleme imza anahtarı (kendi Mac'inizde, bir kez)
```bash
npx @tauri-apps/cli@2.11.4 signer generate -w ~/.tauri/erbaa-kurs.key
# parola sorar → TAURI_SIGNING_PRIVATE_KEY_PASSWORD
cat ~/.tauri/erbaa-kurs.key       # → secret TAURI_SIGNING_PRIVATE_KEY
cat ~/.tauri/erbaa-kurs.key.pub   # → tauri.conf.json › plugins.updater.pubkey (ya da variable TAURI_UPDATER_PUBKEY)
```
Anahtar dosyasını parola yöneticisine/yedeğe alın.

### 8.4 Sırları ekleme
* Web: depo › Settings › Secrets and variables › Actions › **New repository secret** (tablodaki her secret) ve
  **Variables** sekmesi › `TAURI_UPDATER_PUBKEY`.
* Ya da `gh` ile: `gh secret set APPLE_CERTIFICATE -R orginscorel/kurs < cert.b64`,
  `gh secret set APPLE_API_KEY -R orginscorel/kurs < AuthKey_XXXX.p8`, `gh variable set TAURI_UPDATER_PUBKEY -R orginscorel/kurs < erbaa-kurs.key.pub`.

### 8.5 Sunucu
* `.env`'e `DESKTOP_GITHUB_TOKEN=…` (salt okur) ekleyin; `php artisan config:clear` gerekmez (config önbelleklenmiyorsa).
* `KURS_DATA_KEY` tanımlı (2026-09-17). Anahtar değişirse yerel kurulumlar yeniden eşleştirilmelidir.
* Paketin `.env` şablonu `APP_ENV=production` (yerel kipte Eloquent katı kipi geliştirme ortamında 500 verir).

## 9. Sürüm çıkarma

```bash
cd desktop
node scripts/release.mjs minor "Başlık" "yeni: …" "iyilestirme: …" "duzeltme: …"   # ya da patch / add
git add -A && git commit -m "desktop: v0.2.0"
git tag desktop-v0.2.0 && git push && git push --tags
# Actions ~30–60 dk (ilk seferde statik PHP derlemesi ~40 dk/mimari; sonra önbellekten)
# Sunucu 15 dk içinde çeker; beklemeden:
sudo -u oritoriu /opt/cpanel/ea-php83/root/usr/bin/php /home/oritoriu/kurs-app/artisan kurs:desktop-release-sync
```
Web uygulaması değiştiyse masaüstünde yeniden yayın gerekir mi? Yerel kip web kodunun **pakete girmiş kopyasını**
çalıştırır: eşitleme protokolü ya da göç değiştiyse masaüstü sürümü de çıkarın (patch yeterli). Yalnız web ekranı
değiştiyse Kip B kullanıcıları hemen görür, Kip A bir sonraki masaüstü sürümünde görür.

Sunucuda paket düzenini denemek (Rust gerekmez):
```bash
cd /home/oritoriu/kurs-desktop
TMPDIR=/root/.cache/kurs-bundle-tmp PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php COMPOSER_BIN=/usr/local/bin/composer bash scripts/bundle-laravel.sh
export DATA=/root/.cache/kurs-bundle-tmp/smoke PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php PHP_EXTRA_INI_DIR=/opt/cpanel/ea-php83/root/etc/php.d
bash scripts/local-smoke.sh init https://kurs.bogahostdeveloper.com.tr
bash scripts/local-smoke.sh pair < girdi.json      # {"server","code","login","password","name","platform"}
bash scripts/local-smoke.sh snapshot
bash scripts/local-smoke.sh serve 18743 <jeton>     # http://127.0.0.1:18743/__desktop/boot?t=<jeton>
```
(`/tmp` bu sunucuda `noexec`: npm ikilileri çalışmaz → `TMPDIR` başka yere.) Deneme bitince DATA klasörünü silin
(canlı verinin kopyasını içerir) ve eşleştirilen cihazı/deneme kullanıcısını kaldırın.

## 10. Sorun giderme

| Belirti | Neden / çözüm |
|---|---|
| "Yerel sunucu açılamadı" | `~/Library/Logs/tr.com.erbaabilgi.kurs/php-server.log`, `php-error.log`, `local/storage/logs/laravel-*.log` (Yardım › Günlük klasörünü aç). |
| Açılışta Anahtar Zinciri parolası soruluyor | İmza değişti (farklı Team ID ya da ad-hoc derleme). "Her Zaman İzin Ver" deyin; kalıcı çözüm aynı Developer ID ile imzalamak. |
| "Uygulama hasarlı / açılamıyor" | Notarization yok ya da zımba eksik: CI'da "DMG imzala, notarize et" adımı ve `spctl` çıktısı. İmzasız deneme DMG'si için sağ tık › Aç. |
| Notarization "Invalid" | `xcrun notarytool log <id> --key … --key-id … --issuer …`; genellikle imzasız bir ikili (gömülü php) ya da hardened runtime eksik. |
| `tauri build`: "resource path … doesn't exist" | `build/laravel` yapıtı inmedi (laravel işi başarısız). |
| `tauri build`: externalBin bulunamadı | `src-tauri/binaries/php-universal-apple-darwin` yok (lipo adımı). |
| HTTPS istekleri "SSL certificate problem" | `runtime/cacert.pem` pakete girmedi. |
| Kurulum "Kurum kodu geçersiz" | Web › Ayarlar › Bağlı cihazlar'daki kodu kullanın (yenilendiyse eskisi geçmez). |
| Kurulum "sync.use yetkisi yok" | Personel rolüne "Masaüstü/mobil uygulamayla eşitleme" yetkisi verin. |
| İlk eşitleme yarıda kaldı | "Yeniden dene" kaldığı yerden sürdürür (yeniden eşleştirmez). |
| Güncelleme gelmiyor | Sunucuda `kurs:desktop-release-sync --dry-run`; `https://…/desktop/latest.json` sürümü; `.env` `DESKTOP_GITHUB_TOKEN`. |
| "Gönderilmemiş değişiklik var" (sıfırlama) | İnternete bağlanıp tepsi › Şimdi eşitle; reddedilenler web'de Eşitleme çakışmaları sayfasında. |
| PDF'lerde yazı tipi hatası | `local/storage/fonts` yazılabilir olmalı (uygulama oluşturur). |

## 11. Sonraki platformlar

**Karar:** masaüstü (macOS → Windows) aynı Tauri 2 projesiyle; **mobil (iOS/Android) Capacitor ile** mevcut React SPA
üzerinden (docs/SYNC.md › Mobil). Gerekçe: mobilde gömülü PHP + SQLite (Kip A) mümkün/uygun değil (iOS'ta süreç
başlatma ve JIT kısıtları, pil); mobil kullanım zaten çevrimiçi öncelikli + işlem kuyruğu. Tauri 2 mobil de mümkündür
ama Capacitor, SPA'yı doğrudan paketleyip olgun eklentiler (push, kamera/QR, güvenli depolama) sunar; Rust kodu mobile
taşınmaz (bu projede `tauri-plugin-updater`, `autostart`, süreç yönetimi masaüstüne özgüdür ve `#[cfg(desktop)]`
ayrımı mobil başlarken eklenmelidir).

* **Windows:** aynı proje. Yapılacaklar: static-php-cli Windows derlemesi (`spc-windows-x64.exe`, `php.exe`;
  `pcntl/posix` yok → uzantı listesi ayrılır), `runtime.rs`'de süreç grubu yerine Job Object / `taskkill /T`,
  `/usr/bin/true` yerine eşdeğer, NSIS/MSI paketleyici, `windows-latest` işi. **Hesap:** kod imzalama sertifikası —
  Azure Trusted Signing (en ucuz, SmartScreen itibarı hızlı) ya da OV/EV sertifika (HSM/USB anahtar ya da bulut imza).
* **iOS:** Capacitor iOS projesi, `macos-14` + Xcode; **Hesap:** aynı Apple Developer Program; App Store Connect'te
  uygulama kaydı, iOS Distribution sertifikası + provisioning profile (ya da fastlane match), TestFlight.
  Push için APNs anahtarı.
* **Android:** Capacitor Android, `ubuntu-latest` + Gradle; **Hesap:** Google Play Console (tek seferlik ücret),
  yükleme anahtarı (keystore → secret), Play App Signing; push için Firebase projesi (FCM).
