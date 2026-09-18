# Erbaa Bilgi Eğitim — Dershane Yönetim Platformu

Canlı: https://kurs.bogahostdeveloper.com.tr · cPanel hesabı `oritoriu` · Durum: `PROJECT_STATUS.md`

## Yığın (neden bu?)
Sunucu paylaşımlı cPanel + LiteSpeed + MariaDB 10.11; PostgreSQL/Redis/sürekli Node süreci YOK.
Bu yüzden: **Laravel 13 (PHP 8.3) API + React 19 SPA (TypeScript, Tailwind 4, Vite 8) + MariaDB**.
Kuyruk/önbellek/oturum = veritabanı sürücüsü; kuyruk işçisi cron `schedule:run` ile her dakika çalışır.
Mobil (Expo) aynı `/api/v1` uçlarını Bearer jetonla kullanır.

## Dizinler
```
/home/oritoriu/kurs-app                      uygulama (web kökünün DIŞINDA)
/home/oritoriu/kurs.bogahostdeveloper.com.tr web kökü: index.php + .htaccess + build/ + storage→ symlink
```
- PHP: `/opt/cpanel/ea-php83/root/usr/bin/php` (varsayılan `php` 8.2 — KULLANMA)
- DB: `oritoriu_kurs`, bağlantı `DB_SOCKET=/var/lib/mysql/mysql.sock` (skip_networking)
- Derleme: `npx tsc -b --noEmit && npx vite build` (çıktı doğrudan web köküne)
- Root olarak dosya yazınca: `chown -R oritoriu:oritoriu /home/oritoriu/kurs-app /home/oritoriu/kurs.bogahostdeveloper.com.tr/build`
- `php artisan migrate` GÜVENLİ (yalnız yeni migration); `migrate:fresh` / `db:wipe` YASAK.
- Demo veri: `DEMO_SEED=1 php artisan db:seed --class=DemoSeeder` — yalnız boş şubede, tek transaction.
- Testler: `php vendor/bin/phpunit --testsuite=Unit`

## Backend kuralları
- **Şube izolasyonu:** operasyonel modeller `BelongsToBranch` kullanır (global scope + otomatik branch_id).
  Kuyruk/komutta `app(BranchContext::class)->run($branchId, fn …)`.
- **Rota:** her modül `routes/api/<modul>.php` (otomatik yüklenir, auth+active grubunda).
  Her uç `->middleware('permission:<anahtar>')`. Yetki anahtarları `app/Support/Permissions.php`.
- **Denetleyici:** `App\Http\Controllers\Api\ApiController` türet; liste yanıtı `paginated()` → `{data, meta}`.
  Sıralama `applySort()` beyaz liste ile; tarih `applyDateRange()`.
- **İş mantığı servislerde** (`app/Services/<Alan>`), denetleyici ince. Kural ihlali →
  `BusinessRuleException('Türkçe kullanıcı mesajı', 'kod', [bağlam], http)`.
- **Hata:** kullanıcı asla teknik mesaj görmez (bootstrap/app.php tek tip JSON hata).
- **Para:** `App\Support\Money` + bcmath string. Float YOK. Tahsilat `PaymentService`, bakiye `Ledger::post`
  (transaction içinde). Payment/FinanceEntry/AccountTransaction/AuditLog DEĞİŞTİRİLMEZ → iptal (void).
- **Denetim:** kritik işlem `Audit::log('alan.eylem', 'insan okunur açıklama', $model, $diff)`.
- **KVKK:** TC `Sensitive::nationalIdColumns()` ile şifreli+HMAC; telefon/TC `students.view_sensitive`
  yetkisi yoksa `Sensitive::mask*` ile maskeli döner. Ham biyometrik veri tutulmaz.
- **Belge numarası:** `Sequence::next('ad', 'ÖNEK')`; yalın sayaç `Sequence::nextNumber()`.
- **Olaylar:** `App\Events\*` `DB::afterCommit` ile atılır; `config('kurs.silent_events')` true ise
  dinleyiciler mesaj üretmez (demo seed).
- **Canlı akış:** `ActivityFeed` satırı yaz; pano 5 sn'de bir `/dashboard/feed?after_id` yoklar.
- Morph adları `AppServiceProvider::enforceMorphMap` — yeni morph modeli oraya ekle.
- Pivot tablo adları migration'daki adla modelde AÇIKÇA verilir (ör. `teacher_subject`).

## Frontend kuralları
- **Modül kaydı:** `resources/js/modules/<ad>/module.tsx` → `{ id, routes, nav, commands }` (bkz. `app/modules.ts`).
  Router/menü/komut paleti otomatik toplar. Ortak dosyaları düzenleme. Referans modül: `modules/students`.
- Sayfalar `lazyPage(() => import('./X'))` + `page(<X />)`.
- API: `api.get/post/put/patch/delete/download` (`lib/api.ts`), hata `ApiError` (`firstError()`).
  Liste durumu URL'de: `useListState()`. Veri: TanStack Query; mutasyon sonrası `invalidateQueries`.
  Tahsilat gibi çift gönderimi tehlikeli işlemlerde `idempotencyKey()`.
- UI kiti (`components/ui`): Button/ButtonLink, Field/Input/Select/Textarea/Checkbox/Switch/Segmented,
  Badge/Avatar/Skeleton/EmptyState/Alert/ProgressBar/Kbd, Modal/Drawer/Menu/ConfirmDialog/Tooltip,
  PageHeader/Panel/Stat/Tabs/DescriptionList, DataTable. Grafik: `components/charts/ChartKit`.
- Renkler yalnız token (`bg-surface`, `text-ink-2`, `bg-primary-soft`, `text-danger`…). Ham hex YOK.
  Grafik serileri `series[n]` (sabit sıra). Durum renkleri (success/warning/danger) yalnız durum için.
- Yetki: `useCan()('izin.anahtari')` — yetkisiz butonu gizle. Var olmayan sayfaya bağlantı verme.
- Format: `lib/format.ts` (money, date, time, relative, phone, num). Metinler Türkçe.
- Tasarım: sade, bol boşluk, ince çizgi (ring-line), kart yığını yok, her ekranda boş durum + iskelet.
- Mobil uyum zorunlu (tablolar `overflow-x-auto`, gridler tek kolona düşer).

## Sürüm ve değişiklik günlüğü
- Kullanıcıya görünen her yayında `resources/changelog.json` güncellenir:
  - yeni özellik → `node scripts/release.mjs minor "Başlık" "yeni: …" "iyilestirme: …"`
  - küçük düzeltme → `patch`
  - aynı sürüme madde → `add "duzeltme: …"`
- Sürüm = günlüğün en üstündeki kayıt. `vite build` sürümü ve derleme kimliğini `build/version.json` + `build/changelog.json` olarak yazar. Açık ekranlar `UpdateNotifier` (resources/js/components/app) ile dakikada bir yoklar ve "Yeni sürüm yayında" penceresini gösterir.
- Yenilikler sayfası `/yenilikler`; sürüm numarası kenar menüsünün altında.
- TEK NUMARA: web ve macOS masaüstü uygulaması aynı sürümü kullanır (17.09.2026'da birleştirildi; masaüstü 0.1.x → 1.11.0). Masaüstü yayını:
  `resources/changelog.json` (web) ve `/home/oritoriu/kurs-desktop/CHANGELOG.json` (masaüstü) aynı numarayı taşımalı → masaüstünde `node scripts/release.mjs sync`, `src-tauri/Cargo.lock` içindeki `erbaa-kurs` sürümü de elle güncellenir → `assemble-repo.sh` → push → `desktop-v<sürüm>` etiketi (CI derler, imzalar, yayımlar) → sunucuda `kurs:desktop-release-sync`.
