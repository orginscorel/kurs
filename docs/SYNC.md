# Çevrimdışı yerel kurulum ↔ web eşitlemesi

Durum: **altyapı canlıda (2026-09-17)**; eksikler tamamlandı (dosya eşitlemesi, tüm finans komutları, kurum veri anahtarı
taşıma aracı, çevrimiçi parola, Bearer oturumsuzluğu). Masaüstü/mobil paketleme henüz yok (bkz. "Sonraki adımlar").

## 1. Mimari özet

```
 Masaüstü (Windows/macOS)                         Web sunucusu (tek doğruluk kaynağı)
 ┌─────────────────────────────┐   HTTPS + Bearer   ┌──────────────────────────────────┐
 │ Aynı Laravel + React        │  (cihaz jetonu,    │ MariaDB                          │
 │ KURS_NODE=local, SQLite     │   yetenek 'sync')  │ sync_changes (değişiklik günlüğü)│
 │ ChangeRecorder → sync_changes ── push ──────────▶ PushService (satır | komut)       │
 │ kurs:sync (dakikada bir)    ◀── pull ─────────── PullService (şube+yetki süzgeci)   │
 │ LocalApplier (DB::table)    ◀── snapshot ─────── SnapshotService (parçalı)          │
 └─────────────────────────────┘                    └──────────────────────────────────┘
 Mobil (Android/iOS): çevrimiçi öncelikli; okuma önbelleği + çevrimdışı işlem kuyruğu → aynı push ucu.
```

* **Kimlik:** eşitlenen her tabloda `uuid` (UUIDv7). Tamsayı `id`'ler DEĞİŞMEDİ; yerel ve sunucu id'leri farklıdır.
  Referans sütunları tel üzerinde uuid taşır (`RowCodec`), alıcı kendi id'sine çevirir. Referans haritası şemadaki
  yabancı anahtarlar + adlandırma kuralları + `SyncRegistry::fk` geçersiz kılmalarından (`SyncSchema::refs`) gelir.
  Morph sütunları (`subject_type/subject_id`) takma addan tabloya çevrilir.
* **Değişiklik günlüğü:** `sync_changes` (id = imleç, change_uuid, branch_id, tablo, row_uuid, op, alanlar, kaynak,
  kaynak cihaz, kullanıcı, zaman). Model dosyalarına dokunulmadan **genel Eloquent dinleyicisi** yazar
  (`App\Sync\ChangeRecorder`, `eloquent.created/updated/deleted: *`). Güncellemede yalnız değişen alanlar yazılır.
* **Olaysız yazmalar (DB::table, toplu update, pivot attach/detach, saveQuietly):** `Sweeper` her satırın özetini
  (`sync_row_hashes`) tutar; özet değişmişse tam satırı `sweep` kaynaklı değişiklik olarak yazar, uuid'siz satıra uuid verir.
  Hızlı kip dakikada bir (tablo parmak izi: sayı + son id + son updated_at), tam kip 30 dk'da bir. Eloquent yazması özeti
  anında günceller (çift kayıt olmaz). `kurs:sync-audit-writes` kodu tarar ve listeler.
* **Silme izi:** soft delete olan tablolarda `deleted_at` güncellemesi; diğerlerinde `delete` kaydı + `sync_tombstones`.
* **Kayıt defteri:** `app/Sync/SyncRegistry.php` — her tablo için tür, yön, şube çözümü, hariç/hassas sütunlar, yetki,
  doğal anahtar. `tests/Unit/SyncInfrastructureTest` tanımsız tabloyu yakalar. **Yeni migration tablo ekliyorsa buraya da
  ekleyin ve `php artisan kurs:sync-prepare` çalıştırın** (idempotent: eksik uuid/updated_at ekler, doldurur, özet alır).

## 2. Tablo türleri

| Tür | Yön | Kural | Örnek |
|---|---|---|---|
| reference | ↕ | Alan bazında son yazan kazanır, çakışma kaydı | students, guardians, teachers, class_groups, lesson_sessions, leads, discipline_* |
| keyed | ↕ | Doğal anahtarla tek satır (farklı uuid'ler birleşir, cihaza `merge`), son yazan kazanır, eski değer günlükte | attendances (ders+öğrenci), daily_presences |
| append | ↕ | Yalnız ekleme | attendance_events, lead_activities, discipline_events |
| ledger | ↓ (↑ yalnız komut) | Ham satır ASLA itilmez; yerel işlem komut olarak gider, sunucu servisiyle yeniden yürütür | payments, enrollments, refunds, finance_entries, invoices, promissory_notes |
| server | ↓ | Sunucu-otoriteli; yereldeki değer geçici | installments, payment_allocations, account_transactions, journal_*, users (yalnız personel), roles/permissions, settings |
| pivot | ↕/↓ | Kimlik = anahtar sütunlarından sabit uuid (v5) | taggables, teacher_subject, model_has_roles (↓) |
| upload | ↑ | Yalnız yukarı | audit_logs |
| derived / local / system | — | Eşitlenmez | risk skorları; mesaj kuyruğu, entegrasyon sırları, bildirimler, canlı akış; oturum, önbellek, kuyruk, sync_* |

Türetilmiş alanlar eşitlenmez, her düğümde `RecomputeService` hesaplar (`kurs:sync-recompute [--fix]`):
`finance_accounts.balance`, `installments.paid_amount/status`, `products.stock`, `exams.participant_count`.

## 3. Protokol (`/api/v1/sync/*`, cihaz jetonu)

| Uç | Açıklama |
|---|---|
| `GET sync/ping` (oturumsuz) | sağlık, düğüm türü, protokol sürümü |
| `POST sync/pair` (oturumsuz) | `{code, login, password, device_name, platform, mode, app_version, public_key}` → `{token, device{code:'D2'…}, key_bundle}` |
| `GET sync/status?pending=N` | sunucu imleci, cihaz özeti, açık çakışma |
| `GET sync/snapshot` | bağımlılık sırasıyla tablolar + satır sayıları + başlangıç imleci |
| `GET sync/snapshot/{tablo}?after=&limit=` | parça (id sırası; pivotta ofset) |
| `GET sync/pull?cursor=&limit=` | imleçten sonrası; `{changes[], cursor, more}`; budanmış günlükte `resnapshot:true` |
| `POST sync/push` | `{base_cursor, device_time, changes[]}` → her değişiklik için `accepted|conflict|rejected|duplicate` |
| `POST sync/rows` | belirli satırların güncel hali (onarım / reddedilen komutun geri alınması) |
| `POST sync/number-blocks` | yalın sayaç bloğu (öğrenci no) |
| `POST sync/key-bundle` | kurum veri anahtarı paketi (status yanıtındaki `data_key` parmak izi değişince cihaz kendisi ister) |
| `POST sync/password` | `{user, current_password, password}` — yalnız çevrimiçi parola değişimi (mevcut parola sunucuda doğrulanır, 5 hatalı deneme/5 dk) |
| `GET sync/files/manifest?since=&limit=` | değişen dosya kayıtları `{disk, path, sha256, size, mime, owner_table, owner_uuid, status}` + imleç (`updated_at|id`) |
| `GET sync/files/{sha256}` | dosya içeriği (gönderilmeden önce özet doğrulanır, `X-Content-SHA256`) |
| `POST sync/files` | çok parçalı `{file, disk, path, sha256}` — cihazda oluşan dosya (sahibi sunucuda olmalı) |
| Yönetim (`sync.manage`) | `overview`, `devices`, `devices/{id}/revoke`, `pairing-code[/rotate]`, `conflicts[/{id}[/resolve]]` |
| Yerel | `local-status`, `local-sync-now` (yalnız KURS_NODE=local) |

Değişiklik biçimi: satır `{id, table, op: insert|update|delete|upsert, row, fields, at}`, komut
`{id, command, args, uuids: {tablo: [uuid…]}, numbers: {sayaç: [no…]}, at}`.

* **Push:** tek transaction, her değişiklik kendi savepoint'inde (biri reddedilirse diğerleri işlenir); `sync_receipts`
  ile idempotent; cihaz saati sapması düzeltilir. Reddedilenler "Eşitleme çakışmaları"nda `Sunucu reddetti` olarak görünür.
* **Pull:** şube süzgeci + tabloya yetki (`permission`) + hassas sütun yetkisi (`students.view_sensitive`). Cihazın kendi
  değişikliği dönmez; **son yazan kazanır ile cihaz kazandıysa** (`echo`) döner ki eski sunucu değeri cihazı geri çevirmesin.
  İmleç güvenliği: 30 sn'den genç boşlukta (süren transaction) sayfa kesilir.
* **Komut (finans):** yerelde `LocalPaymentService`/`LocalEnrollmentService`/`LocalCardPaymentDetails` asıl servisi
  çalıştırır ve aynı transaction'da komutu yazar (oluşan satırların uuid'leri + verilen belge numaraları). Sunucu aynı
  servisi **aynı uuid ve numaralarla** yeniden yürütür → iki taraf aynı satırlara yakınsar; sunucu sonucu otoriterdir.
  Kullanılmayan uuid'ler cihazda silinir; reddedilen komutun geçici satırları silinir, değişen satırlar sunucudan geri yüklenir.
  Komutlar (`app/Sync/Commands/CommandRegistry.php`):

  | Komut | Yerel sarmalayıcı | Not |
  |---|---|---|
  | `payment.collect` / `payment.void` / `payment.card_details` | LocalPaymentService, LocalCardPaymentDetails | çift tahsilat → avans + mutabakat |
  | `enrollment.create` | LocalEnrollmentService | kayıt + plan + sözleşme |
  | `refund.create` / `refund.void` | LocalRefundService | iade no `IAD-D2-…`; tahsilat web'de iptal/iade edilmişse iade edilebilen kısım iade, artanı **"Mükerrer iade farkı"** gideri + mutabakat |
  | `finance_entry.create` / `finance_entry.void` | LocalFinanceEntryService | gelir/gider |
  | `account_transfer.create` / `account_transfer.void` | LocalAccountService | hesaplar arası aktarım (açılış/sayım farkı çevrimdışı ENGELLİ) |
  | `pos_settlement.create` / `pos_settlement.void` | LocalReconciliationService | içteki aktarım + komisyon gideri aynı komutta; ekstre işaretleme ENGELLİ |
  | `promissory_note.prepare` / `promissory_note.print` | LocalPromissoryNoteService | yeni senetler **taksit bazında** (`notes: {taksit uuid: [senet uuid, senet no]}`) gider: basılı numara korunur; web'de o taksidin geçerli senedi zaten varsa web'deki kalır, cihazınki silinir + mutabakat. Basım kaydı PDF üretmeden sayaç/zaman olarak işlenir |
  | `collection_note.add` / `.status` / `.reminders` | LocalCollectionService | tahsilat takip notu; mesaj GÖNDERİLMEZ |
  | `installment_plan.restructure` / `.adjust_price` | LocalInstallmentPlanService | satır kimlikleri `rows.*.id` joker yolla çevrilir; kaydın tüm taksitleri "dokunulan" sayılır (web kuralı reddederse yerel plan geri yüklenir) |

  Hâlâ yerelde **açık hata ile engelli**: fatura/e-Arşiv, hesap açma/düzenleme, açılış bakiyesi, kasa sayım farkı, banka ekstre
  eşleştirmesi, yevmiye elle fiş, dönem kapama, stok, kullanıcı/rol/parola (parola için bkz. Güvenlik).
* **Yeniden yürütme güvenliği:** aynı değişiklik kimliği `sync_receipts` ile tekil; aynı komut farklı kimlikle gelirse (cihaz
  veritabanı geri yüklendi) oluşturduğu uuid'ler zaten o cihazın önceki komutuna aitse `duplicate` döner. Sunucu komutu
  yürütürken nakit kasa sunucu bakiyesine göre eksiye düşerse işlem **reddedilmez** (para fiilen hareket etti) ve mutabakat
  kaydı düşer (`Ledger` + `SyncContext::replayingDeviceCommand`). Web'den yapılan işlemde kasa kuralı aynen geçerli.
* **Sunucu kancaları:** yeni öğrenci → portal hesabı; öğrenci durumu → `StudentService::changeStatus` (sınıf/kayıt/portal
  yan etkileri); veli → veli portal hesabı. Yan etkiler web kaynaklı değişiklik olarak tüm cihazlara iner.

## 4. Çakışma kuralları

* Referans/profil: alan bazında son yazan kazanır (cihaz saati sunucu saatine göre düzeltilmiş). Her çakışma
  `sync_conflicts`'e düşer; ekranda "Geçerli değer kalsın" ya da "Diğer değeri uygula".
* Silme ↔ güncelleme: sunucuda sonradan güncellenen kayıt silinmez (cihaza tam satır `upsert` geri gider);
  silinmiş kayda gelen güncelleme uygulanmaz. İkisi de kayıt edilir.
* Yoklama: aynı öğrenci-ders için son yazan kazanır, önceki değer günlükte.
* Finans: asla ezilmez. Aynı taksite iki yerden ödeme → iki tahsilat da kalır (fazlası sonraki açık taksitlere, artanı avans),
  "Finans mutabakatı" kuyruğuna düşer. Aynı tahsilattan iki yerde iade → iade edilebilen kısım iade, artanı gider + mutabakat.
  Aynı taksite iki yerde senet → web'deki geçerli, cihazınki iptal + mutabakat. Kasa eksiye düşerse → işlem yazılır + mutabakat.
* Ödeme planı değişikliği para hareketi değildir: web kuralına uymazsa (ör. taksit çevrimdışıyken ödendi) reddedilir, cihaz geri alır.
* Dosya: sunucudaki dosyanın üzerine asla yazılmaz (aynı yol + farklı içerik → 409, cihazda "başarısız" olarak kalır).

## 5. Numaralar

* Belge numaraları (makbuz, kayıt, sözleşme, iade, senet, disiplin): yerelde cihaz kodu eklenir → `MKB-D2-2026-000123`;
  sunucu cihazın numarasını AYNEN kullanır (basılı makbuz değişmez). Sunucu kendi sırasına (`MKB-2026-…`) devam eder;
  çakışma imkânsız.
* Yevmiye (`journal`) sunucuda kendi sırasıyla verilir (yereldeki geçici numara çekmede düzelir).
* Öğrenci no (portal kullanıcı adı): sunucudan 50'lik blok (`sync_number_blocks`); sunucu sayacı bloğun sonuna ilerler;
  blok 10'un altına inince tur başında yenilenir; bitince açık hata.
* **Fatura / e-Arşiv:** GİB seri+sıra numarası kesintisiz ve kronolojik olmalı, entegratörde çevrimiçi onaylanır.
  Cihaz önekli seri teknik olarak mümkün olsa da (her cihaza ayrı GİB serisi) boşluk/sıra ve entegratör gereksinimi
  nedeniyle **fatura yalnız çevrimiçi kesilir**; yerelde fatura numarası istenirse açık hata döner.

## 5b. Dosyalar (içerik adresli)

| Kaynak | Disk | Yön |
|---|---|---|
| `students.photo_path` (öğrenci fotoğrafı) | public | ↕ |
| `documents.path` (öğretmen belgesi, ödev dosyası, öğrenci ödev teslimi — portal dahil, disiplin eki, öğrenci belgesi) | satırdaki `disk` | ↕ |
| `teachers.avatar_path` | public | ↕ |
| `users.avatar_path` (personel), `branches.logo_path`, ayar `institution.logo_path` (kurum logosu) | public | ↓ |

* Yol satır alanı olarak normal eşitlenir; içerik `sync_files` dizini ile taşınır. Dosya **aynı göreli yola** yazılır (yol adlarında
  rastgele ek olduğu için çakışmaz). Yerel diskte karşılığı `storage/app/public` (web'de `/storage` bağlantısı) ve
  `storage/app/private` — paketlemede uygulama veri dizininde tutulmalı.
* Sunucu: `FileIndex` başvurulan dosyaların sha256'sını tutar (değişmeyen dosya mtime+boyutla atlanır; başvurusu kalkan `gone`),
  zamanlayıcıda 10 dk'da bir + manifest öncesi en sık dakikada bir. `kurs:sync-files --index`.
* Yetki: yalnız cihaz jetonu (portal hesabı eşleştiremez → portal dosyasına cihaz ucundan erişemez); şube kapsamı; sahip tablonun
  görme yetkisi (`students.view`, `documents.view` + belge türüne göre `discipline.view` / `homework.view` / `teachers.view`).
* Yükleme doğrulaması: yol kalıbı + uzantı listesi (`FileSources`), boyut ≤ `SYNC_FILE_MAX_MB` (20), sha256 eşleşmesi, sahibi
  sunucuda cihazın şubesinde olmalı (yoksa 404 → yeniden denenir), var olan farklı dosyanın üzerine yazılmaz (409).
* Yerel (`LocalFileSync`, `kurs:sync` turunun son adımı): tarama → yeni yerel dosya `upload`; manifest → eksik/farklı dosya
  `download`; tur başına en çok `SYNC_FILES_PER_CYCLE` (40); hata → üstel geri çekilme (15 sn … 6 sa), 12 denemeden sonra
  `failed` ama 6 saatte bir yine denenir; indirilen dosya geçici adla yazılır, özet doğrulanınca yerine taşınır.
  `kurs:sync-files [--retry]` durum ve anında yeniden deneme. Gösterge özetinde `files_pending`.
* Not: `students` dışlama listesinden `photo_path` çıkarıldığı için süpürücü 17.09'da 200 öğrenci satırını bir kez tam satır olarak
  yeniden günlüğe yazdı (cihazlara zararsızca tekrar iner). Kayıt defterinde `exclude` değiştirilince `kurs:sync-prepare --rebaseline`.

## 6. Güvenlik

* Eşleştirme: kurum kodu (Ayarlar › Bağlı cihazlar, yenilenebilir) + `sync.use` yetkili **personel** hesabı. Öğrenci, veli
  ve yalnız-öğretmen-portalı hesapları reddedilir. Jeton yalnız `sync` yeteneği taşır ve başka uçlarda kullanılamaz
  (`RestrictSyncTokens`); normal jeton da eşitleme uçlarına giremez. Cihaz iptali jetonu siler. Cihaz uçları oturum açmaz.
* Cihaza inmeyenler: öğrenci/veli portal hesapları ve başlangıç şifreleri, `remember_token`, entegrasyon/webhook sırları,
  iCal jetonları, cihaz API jetonları, eşleştirme kodu, mesaj kuyruğu. Personel parola **özetleri** (bcrypt) iner (çevrimdışı giriş için).
* TC ve benzeri şifreli alanlar: `Sensitive` artık **kurum veri anahtarı** (`KURS_DATA_KEY`) destekler; tanımlı değilse
  APP_KEY (bugünkü davranış). Eşleştirmede cihaz X25519 açık anahtarı gönderir; sunucu veri anahtarını **sealed box** ile
  mühürler (yalnız cihazın gizli anahtarı açar), yerelde yerel APP_KEY ile şifreli saklanır (paketlemede OS anahtar zinciri).
  **APP_KEY cihaza verilmez** (oturum/çerez imzası); ancak `SYNC_SHARE_APP_KEY=true` ile açıkça izin verilirse.
  Veri anahtarı yoksa `students.view_sensitive` yetkisiyle bile şifreli TC cihazda çözülemez ve yerelde TC girişi açık hatayla engellenir.
  Kod anahtar tanımlıyken **hem yeni hem eski** şifreyi okur (`Sensitive::decryptDetailed`) ve TC aramalarında eski özeti de arar
  (`Sensitive::hashes`). Kapsam: öğrenci/veli TC (şifreli + HMAC özet), fatura alıcı vergi no ve senet borçlu TCKN
  (`App\Casts\DataEncrypted`). Kapsam dışı (APP_KEY'de kalır, cihaza inmez): entegrasyon/webhook sırları, portal başlangıç şifreleri.
* **Kurum veri anahtarı taşıma planı (`php artisan kurs:data-key …`, KULLANICI ONAYIYLA):**
  1. `kurs:data-key status` → hangi alan hangi anahtarla (17.09 canlı: öğrenci 200, veli 183, fatura 7, senet 17 — hepsi APP_KEY).
  2. `kurs:data-key migrate --dry-run` → anahtar yokken de bellekteki geçici anahtarla sayar (17.09 canlı: 407 alan taşınacak, 0 çözülemeyen).
  3. `kurs:data-key generate --force` → `.env`'ye `KURS_DATA_KEY` yazar (önce `.env.bak-datakey-…` yedeği; anahtar ekrana basılmaz,
     yalnız parmak izi) → `php artisan config:clear` (config önbelleği kullanılıyorsa `config:cache`).
  4. `kurs:data-key migrate --force` → önce `kurs:backup` DB yedeği; eski şifreli değer + eski özet `sync_key_backups`'a
     (düz metin YOK); satır `updated_at` güncellenir → süpürücü değişikliği cihazlara indirir. İdempotent (ikinci çalıştırma 0).
  5. Eşleşmiş cihazlar bir sonraki turda `data_key` parmak izini görüp mühürlü paketi kendileri ister.
  6. Geri dönüş: `kurs:data-key rollback [--batch=…] [--dry-run]` (taşımadan sonra değişen satıra dokunmaz) + `.env`'den satırı
     kaldırıp `config:clear`. APP_KEY değiştirilmemelidir (eski değerler onunla açılır).
  **UYARI:** `KURS_DATA_KEY` kaybolursa taşınmış TC'ler okunamaz → `.env` yedeğiyle birlikte güvenli yerde saklayın.
* **Parola:** yerelde personel hesabı (`users`, personel) değişikliği ENGELLİ (sessizce kaybolmasın: `ChangeRecorder::guard`,
  yalnız `last_login_at/ip`, `remember_token` serbest; yerelde girişte yeniden özetleme kapalı). Kendi parolasını değiştirme
  (`auth/change-password`) yerelde **yalnız çevrimiçi**: `LocalPasswordProxy` → `sync/password`; sunucu mevcut parolayı doğrular,
  yeni özet hemen yerele yazılır, web oturumları ve (eşitleme dışı) jetonlar kapanır. Çevrimdışıysa açık hata.
  *Neden kuyruk değil:* kuyruk düz parolayı ya da mevcut parolayı cihazda bekletirdi; ele geçirilen bir cihaz veritabanından
  başka personelin web parolası değiştirilebilirdi. Portal (öğrenci/veli) hesapları düğüme özeldir, sunucu kendisi açar.
* **Bearer oturumsuzluğu:** API grubunda `StartSessionUnlessBearer` — oturum çerezi taşımayan Bearer isteklerinde (mobil, köprü,
  cihaz) oturum başlatılmaz, `sessions` tablosuna satır yazılmaz; web paneli (çerez) aynen çalışır. Bearer ile `auth/login`
  çağrılırsa 400 `session_required` (uygulamalar `auth/token` kullanır).
* Yerel düğümde dış istek yasağı (`Http::preventStrayRequests`, yalnız eşitleme sunucusu), posta = log, `silent_events`,
  zamanlayıcıda yalnız `routes/schedules/sync.php` (mesaj, otomasyon, yedek, oturum üretimi yalnız sunucuda).

## 7. Yerel düğüm

```
.env: KURS_NODE=local, DB_CONNECTION=sqlite, DB_DATABASE=…/kurs-local.sqlite, MAIL_MAILER=log
php artisan migrate --force                 # tüm migration'lar SQLite uyumlu
php artisan kurs:sync-pair https://kurs.… KOD kullanici --password=… --name="Ofis PC" --platform=windows --snapshot
php artisan schedule:work  (ya da işletim sistemi zamanlayıcısı)  → kurs:sync --loop=55 dakikada bir
php artisan kurs:sync --status | --snapshot | --force
php artisan kurs:sync-files [--retry]      # dosya kuyruğu
```

* `SqliteCompatConnection`: uygulamadaki MySQL'e özgü ham SQL'i yeniden yazar (TIMESTAMPDIFF birimi, GROUP_CONCAT
  SEPARATOR, MATCH…AGAINST, LEFT/RIGHT, elle yazılmış `a.status = "absent"` / `IN ("x","y")` çift tırnaklı metinleri) ve eksik
  fonksiyonları kaydeder (CONCAT, DATE_FORMAT, FIELD, REGEXP, IF…). Sunucu kipinde SQLite (yalnız deneme/CI): `SYNC_SQLITE_MYSQL_COMPAT=true`.
* **Uyumluluk taraması (17.09, gerçek anlık görüntüyle):** 275 GET ucu + 6 POST önizleme (raporlar, panolar, sınav analizi, finans
  kokpiti, muhasebe/yevmiye/mizan/defter, disiplin raporu + PDF, toplu gönderim önizlemesi, liste çıktısı, program/takvim) —
  500 kalmadı. Tek SQLite hatası devam raporundaki çift tırnaklı metindi (düzeltildi). Diğer 4xx'ler yetki/doğrulama.
* **Paket `APP_ENV=production` olmalı:** `local` kipte Eloquent katı kipi (`Model::shouldBeStrict`) seçilmemiş sütuna erişimde 500
  verir (finans tahsilat/iade/gider/fatura listeleri, rehberlik görüşmesi ve etüt ayrıntısı — üretimde sessizce null; ayrıca
  düzeltilmeli).
* SQLite: WAL + busy_timeout; önbellek dosyada (veritabanı önbelleğindeki oku-yaz yükseltmesi SQLite'ta anında kilit verir).
  PHP 8.4+ ile `transaction_mode=IMMEDIATE` etkinleşir (paketlerde 8.4 önerilir).
* Durum: imleçler `sync_state` tablosunda (uygulanan değişikliklerle aynı transaction), anlık görünüm
  `storage/app/private/sync/state.json`. Hata → üstel geri çekilme (15 sn … 10 dk). Üst çubuk göstergesi
  (`components/layout/SyncStatus.tsx`) yalnız yerel kurulumda (`<meta name="kurs-node" content="local">`).

## 8. Sonraki adımlar: paketleme (bu sunucuda derlenemez)

**Masaüstü — öneri: Tauri 2 + gömülü statik PHP (static-php-cli / FrankenPHP) + SQLite**
* Tauri kabuğu (Rust, küçük boyut, OS anahtar zinciri eklentisi, güncelleyici) uygulama açılışında gömülü PHP'yi
  `127.0.0.1:<rastgele port>` üzerinde başlatır (FrankenPHP tek ikili: web sunucusu + `php-cli`), WebView bu adresi açar.
  Zamanlayıcı: Tauri arka plan görevi dakikada bir `php artisan schedule:run`.
* Alternatifler: **NativePHP** (Laravel'e özel, Electron/Tauri üzerinde; hızlı başlangıç ama Laravel 13 + çok modüllü
  yapı ile olgunluğu doğrulanmalı), **Electron + php-binary** (en olgun, ~150 MB, bellek yüksek). Karar ölçütü:
  imzalama (Windows EV sertifikası, Apple notarization), otomatik güncelleme, boyut.
* Paket içeriği: `vendor/` (–dev), derlenmiş `build/`, PHP 8.4 (pdo_sqlite, sodium, intl, gd, zip, bcmath), boş SQLite,
  ilk açılış sihirbazı (sunucu adresi + kurum kodu + personel girişi → `kurs:sync-pair --snapshot`).
* Veri anahtarı ve cihaz jetonu OS anahtar zincirinde (macOS Keychain / Windows Credential Manager).
* Paket kontrol listesi: `APP_ENV=production`, `KURS_NODE=local`, SQLite + WAL, `storage` uygulama veri dizininde ve web kökünde
  `/storage` bağlantısı (fotoğraflar), `php artisan migrate --force` (960100 dahil), PHP 8.4 `pdo_sqlite sodium gd intl bcmath zip`,
  zamanlayıcı dakikada bir `schedule:run` (içinde `kurs:sync --loop=55` → dosyalar ve anahtar yenileme dahil).

**Mobil — öneri: Capacitor (mevcut React SPA) çevrimiçi öncelikli**
* SPA `/api/v1` uçlarını Bearer ile kullanır; okuma önbelleği (TanStack Query persist + IndexedDB/SQLite eklentisi),
  çevrimdışı işlem kuyruğu (yoklama, tahsilat) `sync/push` komut biçimiyle gönderilir (`mode=mobile` cihaz eşleştirmesi).
  Mobilde tam anlık görüntü yok; yalnız kullanıcının ekranlarının verisi.
* Yoklama için satır değişikliği (`attendances`, doğal anahtar birleşmesi hazır), tahsilat için `payment.collect` komutu
  (numara: cihaz öneki).

**GitHub Actions taslağı** (`.github/workflows/desktop.yml`):

```yaml
name: desktop
on: { push: { tags: ['v*'] }, workflow_dispatch: {} }
jobs:
  web:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 22 }
      - run: npm ci && VITE_MODULES=$(node -e "…modül listesi…") node scripts/gen-modules.mjs && npx tsc --noEmit -p . && npx vite build
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', extensions: pdo_sqlite, sodium, intl, bcmath, zip }
      - run: composer install --no-dev --optimize-autoloader && php artisan test
      - uses: actions/upload-artifact@v4
        with: { name: app, path: . }
  bundle:
    needs: web
    strategy: { matrix: { os: [windows-latest, macos-14] } }
    runs-on: ${{ matrix.os }}
    steps:
      - uses: actions/download-artifact@v4
        with: { name: app, path: app }
      - run: ./desktop/fetch-static-php.sh   # static-php-cli / frankenphp ikilisi
      - uses: tauri-apps/tauri-action@v0
        env:
          TAURI_SIGNING_PRIVATE_KEY: ${{ secrets.TAURI_SIGNING_PRIVATE_KEY }}
          APPLE_CERTIFICATE: ${{ secrets.APPLE_CERTIFICATE }}
          WINDOWS_CERTIFICATE: ${{ secrets.WINDOWS_CERTIFICATE }}
        with: { projectPath: desktop, tagName: ${{ github.ref_name }}, releaseDraft: true }
  mobile:
    needs: web
    runs-on: macos-14
    steps:
      - run: npx cap sync && (cd android && ./gradlew bundleRelease)   # iOS: xcodebuild archive + fastlane
```

## 9. İşletim

* Hazırlık/yeni tablo: `kurs:sync-prepare`; süpürme: `kurs:sync-sweep [--full]` (zamanlayıcıda); budama:
  `kurs:sync-prune` (90 gün; eski imleçli cihaz yeniden anlık görüntü alır); sapma: `kurs:sync-recompute [--fix]`;
  olaysız yazma taraması: `kurs:sync-audit-writes [--json=…]`; dosya dizini `kurs:sync-files --index`;
  kurum veri anahtarı `kurs:data-key status|generate|migrate|rollback`.
* Migration `2026_09_17_960100_create_sync_files_and_key_backups` (yalnız ekleme): `sync_files`, `sync_key_backups`.
* Kurallar: Ledger satırlarını cihazdan satır olarak kabul etmeyin; yeni finans işlemi çevrimdışı desteklenecekse
  `CommandRegistry` + `FinanceCommands` işleyicisi + yerel sarmalayıcı (servis alt sınıfı, `LocalCommandRecorder::run`) +
  `SyncServiceProvider` bağlaması ekleyin; toplu sorguyla değişen satırları `LocalCommandRecorder::touch` ile bildirin.
  Yeni dosya sütunu → `App\Sync\Files\FileSources`.
* Testler: `tests/Unit/SyncInfrastructureTest.php`, `tests/Unit/SyncGapsTest.php`. Uçtan uca betikler:
  `/home/oritoriu/_backups_kurs/esitleme-eksikler-20260917/araclar/`.

## 10. Masaüstünde eksik kalan bölümler (1.12.3)

* **Terminaller (`devices`) REFERENCE**, doğal anahtar `[branch_id, serial_no]`. Yazma YALNIZ masaüstünde: web'deki
  ekleme/düzenleme/silme/jeton/keşif/bağlantı testi/çekme/eşleştirme uçları sunucuda **409 `terminal_desktop_only`**
  (`EnsureTerminalDesktop`, rota ara katmanı `terminal.desktop`; QR kimliği `terminal.desktop:identity` ile web'de serbest).
  Düğüme özel sütunlar (`exclude`): api_token_hash/prefix, last_seen_at, last_ip, zk_cursor_*, zk_last_*, adms_stamp.
  Boş bırakılamayan hariç sütun alıcıda `fill` değeri alır (`api_token_hash` → rastgele 64 hane). İletişim şifresi
  kurum veri anahtarıyla şifreli metin olarak taşınır, yalnız `devices.manage` ile iner; web yanıtında IP/şifre DÖNMEZ.
* **Eşlemeler (`device_identities`) REFERENCE**, anahtar `[branch_id, kind, identifier]` (önceden yalnız aşağı yönlüydü:
  Mac'te yapılan eşleme web'e çıkmıyordu).
* **Terminal durum raporu:** yerel `TerminalStatusReporter` (turda en fazla dakikada bir) → `POST sync/terminal-status`
  → `sync_terminal_reports` (SYSTEM). Sunucu `devices.last_seen_at`'i yalnız ileri taşır (otomatik yoklama köprüyü canlı
  görür; günlüğe girmez). Web: `GET attendance/devices` → `data[].bridge = {via, reported_at, last_seen_at, last_pull_at,
  status, error, record_count, connected, details}`. Ek teşhis alanları: `App\Sync\Contracts\TerminalStatusProvider`.
* **Bildirimler (`app_notifications`) SERVER**, süzgeç `staff_owned` (yalnız personelin), şube `via:user_id`. Masaüstünde
  "okundu" → kuyruk (`sync_state.notification_reads_pending`) → `POST sync/notification-reads`. Yerelde üretilen (uuid'siz)
  bildirim yerelde kalır. Neden satır olarak yukarı değil: sunucu aynı işlemi (komut/kanca) yeniden yürütürken bildirimi
  kendisi üretir → çift bildirim olurdu.
* **Sonradan katılan tablolar (`since`):** eşleşmiş kurulum `LocalSyncEngine::lateTables` ile her birini BİR KEZ çeker;
  yalnız bu kurulumda duran satırlar sunucuya sorulup (`sync/rows`) gönderilir. Durum `sync_state.late_tables_done`.
  Hata turu düşürmez, 10 dk sonra yeniden dener. Yeni tabloyu sonradan eşitlemeye alırken `since` verin.
* **Yalnız web bölümleri:** ön yüz `lib/webOnly.ts` (liste) → masaüstünde "… web'den yönetilir" + "Web'de aç" ve menüde
  "web" rozeti; arka uç `web.only` ara katmanı (`EnsureWebNode`) yerelde mesaj gönderimi, şablon, otomasyon, kampanya,
  ret listesi, entegrasyon/mesaj kanalı ve webhook yazmalarını 409 `web_only` ile reddeder (sessizce kaybolmasın).
* Migration `2026_09_18_990100_sync_devices_and_notifications` (yalnız ekleme): devices.uuid, app_notifications.uuid +
  updated_at, `sync_terminal_reports`. Yerelde o ana kadar yalnız Mac'te duran terminaller "eklendi" olarak kuyruğa yazılır.
* Testler: `tests/Unit/SyncDesktopGapsTest.php`.
