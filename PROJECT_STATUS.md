# Erbaa Bilgi Eğitim — Proje Durumu

**Adres:** https://kurs.bogahostdeveloper.com.tr · **Başlangıç:** 2026-09-14

## Faz 0 — Temel (TAMAMLANDI)
- [x] Alt alan adı (kök `/home/oritoriu/kurs.bogahostdeveloper.com.tr`, userdata ile public_html dışında), SSL
- [x] Laravel 13 + React 19 + TS + Tailwind 4 + Vite 8, MariaDB `oritoriu_kurs`
- [x] Şema: 95+ tablo (şube hazır), FULLTEXT arama indeksleri
- [x] Kimlik doğrulama: web oturumu (httpOnly) + mobil jeton, kaba kuvvet sınırı, oturum/cihaz yönetimi, parola değişimi
- [x] RBAC: 63 yetki, 10 varsayılan rol (spatie), süper yönetici kapısı
- [x] Tasarım sistemi (token, açık/koyu tema), UI kiti, DataTable, uygulama kabuğu, komut paleti, bildirim merkezi
- [x] Modül kayıt sistemi (`resources/js/modules/*/module.tsx`, `routes/api/*.php`, `routes/schedules/*.php`)
- [x] Çekirdek servisler: EnrollmentService, PaymentService (+void), Ledger, ExamScoring/ExamResultService,
      PresenceService (idempotent), AutoAttendanceService, ScheduleConflicts, SessionGenerator, StudentInsights (risk)
- [x] Kontrol Merkezi (KPI + grafikler + canlı akış), Bugün ekranı (canlı kurum haritası), global arama
- [x] Öğrenci modülü (liste/filtre/toplu işlem/Excel, 360° Komuta Merkezi, form+kayıt+ödeme planı), Veliler
- [x] Zamanlanmış işler: gecikmiş taksit, oturum üretimi, otomatik yoklama, risk, KVKK temizliği (cron eklendi)
- [x] Birim testleri: sınav puanlama, taksit planı, para, TC, telefon (17 test)

## Faz 1 — Modüller (DEVAM EDİYOR, paralel)
| Modül | Durum | Sahip |
|---|---|---|
| Akademik (program, ders/konu, derslik, sınıf, ders programı, etüt/birebir, ödev) | **YAYINDA** (2026-09-15) | ajan |
| Finans (tahsilat+makbuz PDF, taksit, alacak, gelir/gider, kasa/banka, raporlar, paket, envanter) | **YAYINDA** (2026-09-15) | ajan (güçlü model) |
| Sınav merkezi (deneme, cevap anahtarı, optik okuma, sonuç, soru/kazanım analizi, görsel rapor) | **YAYINDA** (2026-09-15) | ajan |
| Yoklama (canlı giriş/çıkış, hızlı yoklama, devamsızlık, cihazlar, köprü servisi `gateway/`, QR) | **YAYINDA** (2026-09-15) — kiosk henüz oturumlu | ajan |
| İletişim (WhatsApp sağlayıcı, kuyruk, şablon, duyuru, otomasyon motoru, webhook, entegrasyonlar) | geliştiriliyor | ajan |
| CRM + Rehberlik + Riskli öğrenciler + hedefler | **YAYINDA** (2026-09-15) | ajan |
| Öğretmen/personel, Ayarlar (kullanıcı/rol, kurum, onboarding, loglar, sistem sağlığı, yedek) | geliştiriliyor | ajan |
| Program botu + sınıf yapısı (`/program-botu`, `/program-botu/sinif-yapisi`): seviye/şube/alan yapısı (ayar `classes.structure`), sınıfa özel müfredat (`class_group_subject_hours`), öğretmen üst sınır/hedef/dahil (`teachers.target_weekly_hours`, `in_timetable`) | **YAYINDA** (2026-09-16) — yapı canlı döneme henüz UYGULANMADI, program uygulanmadı (onay bekliyor) | ajan |
| Takvim (`/takvim`, modül `calendar`): birleşik takvim, tatil ekle/düzenle/sil (ders iptali), iCal akışı oluştur/durum/iptal (`GET/POST calendar/feeds`, `calendar/feeds/revoke`), haftalık program PDF penceresi | **YAYINDA** (2026-09-16) | ajan |
| Rapor merkezi (`/raporlar`, modül `reports`, `routes/api/reports.php`, `App\Services\Reports\ReportService`): öğrenci listesi, yoklama/devamsızlık (Excel+PDF), tahsilat-alacak (`/finance/reports` uçları), sınav özeti, ön kayıt dönüşümü (eski `/crm/rapor` buraya yönlenir; `/crm/adaylar` → `/on-kayit`), öğretmen ders yükü. Yetki: `reports.view` + modül izni (VE), dışa aktarma + `reports.export`. Menüde tek öğe; yetkisiz rapor sorgu atmaz | **YAYINDA** (2026-09-16) | ajan |
| İçe aktarma sihirbazı | sırada | |
| Öğrenci portalı (`/portal`, `routes/api/portal.php`) + öğrenci hesabı + "Öğrenci olarak giriş yap" önizlemesi | **YAYINDA** (2026-09-16) | ajan |
| Veli portalı (aynı `/portal`, rol farkıyla) + veli hesabı + "Veli olarak giriş yap" + ilk girişte şifre zorunluluğu | **YAYINDA** (2026-09-17) | ajan |
| Öğretmen portalı (`/ogretmen`, `routes/api/teacher-portal.php`) + öğretmen hesabı + "Öğretmen olarak giriş yap"; öğrenci/veli portal ekleri | **YAYINDA** (2026-09-17) | ajan |
| Mobil (Expo) | sırada | |

## Demo veri
`DEMO_SEED=1 php artisan db:seed --class=DemoSeeder` (boş şubede, tek transaction). Demo kullanıcıları:
`storage/app/private/demo-users.txt`. İlk yönetici parolası: `/root/.config/bogahost/kurs-admin.txt`.

## Yayın
`VITE_MODULES=core,students,academic,attendance,exams,crm,guidance,coaching,finance,placement,communication,integrations,staff,settings,portal,calendar,reports,teacher-portal node scripts/gen-modules.mjs && npx tsc --noEmit -p . && npx vite build` — listede olmayan modül derlemeye girmez.

## Öğrenci portalı (2026-09-16)
- Hesap: `students.user_id` → `users` (user_type=student, rol `ogrenci`). Kullanıcı adı = öğrenci no. `StudentService::create`
  (ön kayıttan dönüştürme dahil) hesabı otomatik açar; eksikler için `php artisan kurs:student-accounts [--dry-run]` (idempotent).
- Başlangıç şifresi `users.initial_password` ("encrypted" cast, 7 karakter, karışmayan harf/rakam). Öğrenci şifresini
  değiştirince silinir. Görme/sıfırlama: `students.credentials`; önizleme: `students.impersonate` (yonetici/mudur/danisman).
- Güvenlik: `routes/api/*.php` (portal.php ve core.php hariç) `staff` ara katmanıyla sarılır → öğrenci/veli hesabı yönetim
  uçlarına giremez. Portal uçları öğrenci id'si ALMAZ (`portal.student` oturumdan çözer). Önizlemede asıl personel id'si
  sunucu oturumunda (`impersonation`), yazma işlemleri `impersonation.readonly` ile engellenir; başlangıç/bitiş Audit::log.
- Gizli rehberlik notu, staff/counselor görünürlüklü görüşmeler, risk puanı portala hiç dönmez (yalnız visibility=guardian).

## Denetim düzeltmeleri — arka uç (2026-09-16)
- Taksit hatırlatma: fark = bugün − vade (`InstallmentReminderPlanner::daysDiff`); ofset/açık-kapalı/saat `finance.*` ayarından,
  komut 5 dk'da bir, ayar saatinden 21:00'e kadar gönderir; `--dry-run`, `--date`, `--now`.
- Otomatik yoklama + "bugün gelmedi": giriş verisi yoksa (`DeviceDataGuard`: cihaz yok / cihazlar `attendance.device_stale_minutes`
  (30) dk sessiz / bugün hiç ENTRY yok) GELMEDİ yazılmaz; yöneticiye günde bir uygulama bildirimi (`attendance.pause_when_no_device_data`).
- KVKK: `kurs:prune` eski gönderilmiş mesajları siler; `kurs:anonymize-withdrawn` (03:45) `students.withdrawn_at` + N gün sonra
  TC/telefon/e-posta/adres/doğum/sağlık/foto + cihaz eşlemesini siler (`anonymized_at`, idempotent). Günlük yedek `backup.daily_enabled`'a bağlı.
- Bağlamsız (komut) ayar okuması ana şubeye düşer (`Settings::primaryBranchId`). `exams.default_wrong_penalty_ratio` kaldırıldı (oran sınav türünde).
- Sınıf yapısı kaydedilmemişse BOŞ (`isConfigured()`, yanıtta `configured`); 9-12 A/B varsayımı yok.
- `student.class_changed` tetikleyicisi + webhook + canlı akış (`OnStudentClassChanged`); şablon/kural PASİF.
- Morf haritası tüm modelleri kapsar; denetim ekranında konu etiketi/bağlantısı (`AuditSubjects`) ve `subject_id` filtresi.
- `leads.converted_at` ("bu ay kayda dönen"); devamsızlık listesi varsayılan gelmedi/geç/izinli/raporlu (`status=all` tümü).
- BusinessRuleException INFO olarak loglanır. Yedekler: `/home/oritoriu/_backups_kurs/denetim-arka-20260916/`.

## Kurulum sihirbazı + Excel içe aktarma (2026-09-16)
- `/kurulum?adim=<kurum|logo|donem|dersler|programlar|derslikler|zaman|siniflar|paketler|ogretmenler|whatsapp|ogrenciler>`; ilerleme
  `GET /onboarding/overview` (settings.manage, `OnboardingController`) sayımlarından. "Kurulumu tamamla" → `POST /onboarding/complete`.
  Ayarlar › Kurum başlığında "Kurulum sihirbazını aç" bağlantısı.
- İçe aktarma: `routes/api/imports.php` → `ImportController` + `app/Services/Imports/*` (ImportValue saf ayrıştırıcılar,
  StudentImporter → StudentService::create, TeacherImporter → TeacherService::create). Akış: `GET imports/{students|teachers}/template`
  → `POST imports/{entity}/preview` (dosya özel diske, `import_jobs.status=mapped`) → `POST imports/{job}/commit` (satırlar yeniden
  doğrulanır, hatalılar atlanır, dosya silinir, `completed`) / `DELETE imports/{job}`. Yetki: öğrenci `students.create`, öğretmen `teachers.manage`.
  Onaylanmayan önizlemeler saatlik zamanlayıcıyla 24 sa sonra silinir (`routes/schedules/imports.php`). Ön uç: `components/import/ExcelImportDialog.tsx`
  (öğrenci/öğretmen listesinde `?ice-aktar=1`). Testler: `tests/Unit/ImportValidationTest.php`.

## Veli portalı + portal güvenliği (2026-09-17)
- Veli hesabı: `guardians.user_id` → `users` (user_type=guardian, rol `veli`). Kullanıcı adı = cep telefonu `05xxxxxxxxx`
  (`GuardianAccountService::usernameFor`; telefon yoksa WhatsApp no). Geçerli TR cep yoksa ya da numara başka hesapta ise hesap
  AÇILMAZ (panelde uyarı; kimse başka hesaba bağlanmaz). Telefon değişince kullanıcı adı taşınır (boşsa). Girişte "0532 …", "+90…" yazımları kabul.
- Otomatik: öğrenci formu/ içe aktarma (`StudentService::syncGuardians`) ve veli düzenleme hesabı açar. Toplu: `php artisan kurs:guardian-accounts [--dry-run]`
  (idempotent; aktiflik eşitler; sorunlu velileri listeler). `kurs:student-accounts` da artık aktifliği eşitler.
- Portal uçları aynı (`routes/api/portal.php`, + `GET portal/context`). `EnsurePortalStudent`: veli için `?student_id` YALNIZ bağlı ve açık
  (silinmemiş, ayrılmamış/mezun olmamış) çocuklar arasından; aksi 403. Öğrenci hesabında parametre yok sayılır. Veli: duyurularda yalnız
  `audience.type=guardians`, rehberlikte yalnız `visibility=guardian`; öğrencinin diğer velileri gösterilmez; ödemelerde "ödeme sorumlusu" + kardeş özeti.
- Personel: `guardians/{id}/portal-account[/credentials|/reset-password]`, `guardians/{id}/impersonate` (yetkiler `guardians.credentials`,
  `guardians.impersonate` → yonetici/mudur/danisman). Önizleme oturum kaydı `kind/target_id/target_name` (eski öğrenci kaydı uyumlu); dönüş `/veliler/:id`.
- İlk girişte şifre: `password.fresh` (`EnsurePasswordChanged`) tüm oturumlu API'de; portal hesabı `must_change_password` ya da `initial_password`
  doluysa yalnız `auth/me|logout|change-password`, `client-errors` açık (403 `password_change_required`). Sıfırlama yeniden zorunlu kılar. Önizleme muaf (yazma kilidi sürer).
  Ön yüz: `modules/portal/PasswordSetup.tsx`. Yeni şifre mevcut şifreyle aynı olamaz; giriş hız sınırı telefon yazımlarını tek sayaçta toplar.
- Hesap kapanma: `Listeners/Portal/SyncPortalAccountsOnStatusChange` (StudentStatusChanged, senkron, sessiz modda da çalışır): Ayrıldı/Mezun → öğrenci
  hesabı pasif + oturum/jeton silinir; veli tüm çocukları kapalıysa pasif; geri dönüşte açılır. Öğrenci silinince veliler de eşitlenir.
- Rehberlik: `guidance_meetings.visible_to_student` (varsayılan false; "Yalnız rehber" kayıtlarında zorla false). Öğrenci portalı bunu kullanır.
- Otomasyon: bağlı olmayan kanalı (WhatsApp/SMS/e-posta) kullanan kural AÇILAMAZ (422 `whatsapp_not_connected`; ekranda uyarı + Entegrasyonlar bağlantısı).
  `GET automations/recommended`, `POST automations/recommended/enable` (WhatsApp bağlıyken; devamsızlık, geç kalma, taksit yaklaşıyor/gecikti, sınav sonucu,
  sınıf değişikliği — veliye giden kurallar). Entegrasyonlar'da WhatsApp bağlı+etkinse "Önerilen veli bildirimlerini aç" (onay penceresi). Kurallar hâlâ KAPALI.
- Testler: `tests/Unit/GuardianPortalTest.php` (bellek içi SQLite şeması). Yedekler: `/home/oritoriu/_backups_kurs/veli-portal-20260917/`.

## Öğretmen portalı + öğrenci/veli portal ekleri (2026-09-17)
- Ön yüz modülü `teacher-portal` (yayın listesinde). Sayfalar: `/ogretmen` (özet), `program`, `yoklama`, `yoklama/:id`, `odevler`,
  `odevler/:id` (teslimleri gör, durum/puan 0-100/geri bildirim), `siniflar` (+ `?sekme=ogrenciler`), `siniflar/:id`, `ogrenci/:id`
  (devam, ödev, sınav, zorlandığı konular, gözlem ekle), `gozlemler`, `talepler` (veli taleplerini yanıtla/kapat), `sinavlar`
  (sınıflarımın ortalaması / kurum ortalaması), `etut`, `duyurular` (okundu), `profil` (şifre değiştir). Kabuk: `TeacherShell.tsx`.
- Yetki: `ogretmen` rolü YALNIZ `teacher_portal.access|attendance|homework|observations`. Yalnız bu role sahip öğretmen hesabı
  (`User::isTeacherPortalUser`) `staff` ara katmanına takılır → yönetim/finans uçları 403. Ek rol (rehber, müdür…) verilirse personel sayılır.
  `portal.teacher` (EnsurePortalTeacher) öğretmeni oturumdan çözer; sınıf/öğrenci erişimi `Services/Teachers/TeacherScope`
  (başka sınıfın/ayrılmış öğrencisi → 403); başka öğretmenin dersi/ödevi → 404. Yoklama dersten 15 dk önce açılır, 7 gün geriye düzeltilebilir.
  Telefon/TC/finans/gizli rehberlik notu dönmez. Önizlemede (`teachers.impersonate`) yazma 403.
- Yönetim: `staff/TeacherDetail.tsx` "Portal girişi" paneli + "Öğretmen olarak giriş yap" (`students/StudentPortalAccountPanel.tsx`
  içindeki ortak panel, `kind="teacher"`). Uçlar `routes/api/teacher-accounts.php` (`teachers.credentials`).
- Öğrenci/veli portalı ekleri (`PortalExtrasController`): `/portal/odevler/:id` (öğrenci metin + en fazla 5 dosya teslimi, değerlendirilene kadar
  düzenlenebilir; puan + öğretmen notu), `/portal/gelisim` (net seyri, ders bazında değişim, zorlanılan konular, aylık devam, yaklaşan sınavlar),
  `/portal/geri-bildirim` (görünür gözlemler + ödev puanları), `/portal/ogretmenler` (veli → öğretmen mesaj/görüşme talebi, `contact_requests`;
  SMS/WhatsApp GÖNDERİLMEZ, yanıt portalda), duyurularda okundu (`announcement_reads`), yoklamada aylık katılım.
- Migration: `2026_09_17_600100_add_teacher_portal` (homework_submissions.answer_text/graded_at/graded_by, student_observations,
  announcement_reads, contact_requests). Testler: `tests/Unit/TeacherPortalTest.php` (+ StudentPortalTest/ReportsCenterTest güncellendi).
- Açık: veli talebi için öğretmene anlık bildirim yok (portal rozeti var); gözlemlerin yönetim ekranında (öğrenci profili) gösterimi yok;
  sınıf hedefli duyurular veliye görünmüyor. Yedek: `/home/oritoriu/_backups_kurs/ogretmen-portal-20260917/`.

## Disiplin ve cezai işlem sistemi (2026-09-17)
- Modül `discipline` (menüde "Disiplin" alanı, Rehberlik'in altında): `/disiplin` genel bakış, `/disiplin/olaylar` (+`?yeni=1`, `?yeni=olumlu`),
  `/disiplin/olaylar/:id` (zaman çizelgesi, savunma, yaptırım, itiraz, veli bildirimi taslağı, ek dosya), `/disiplin/dikkat`, `/disiplin/kurul[/:id]`,
  `/disiplin/katalog` (davranışlar, yaptırım kademeleri, ayarlar). Öğrenci profilinde "Disiplin" sekmesi. Rapor Merkezi › Disiplin (`/raporlar/disiplin`, Excel 4 sayfa + PDF).
- Şema (`2026_09_17_710100_create_discipline_tables`, yalnız ekleme): discipline_behaviors, _sanction_types, _incidents (+_incident_students), _defenses,
  _board_meetings (+_board_items), _sanctions, _appeals, _events. Varsayılan katalog 28 davranış (6 olumlu) + 7 kademe her şubeye; şablonlar
  `discipline.sanction|defense|positive.guardian`; tetikleyici `discipline.sanction_decided` + PASİF kural. Sayaçlar: DSP / TKD / YPT / DKR.
- Kurallar `App\Services\Discipline\DisciplineRules` (saf): kurul kademesi (kınama, uzaklaştırma, kayıt sonlandırma önerisi) HER ZAMAN öneri başlar,
  yalnız kurul kabulüyle (oy çokluğu, oy ≤ katılan üye, öncesinde savunma alınmış/“alınmadı” işaretli) yürürlüğe girer; diğer kademeleri `discipline.decide` verir.
  İtiraz kararı kademeye göre decide/board. Uzaklaştırma 1–30 gün, çakışma engellenir. Süreli yaptırım `expires_on`'da düşer (`kurs:discipline-sweep` 00:20).
  Asılsız kapatılan olayın puanı sayılmaz. Dönem net puanı = ceza − olumlu (ayar); eşikler 10/20/35 (dikkat/uyarı/kritik).
- Bağlantılar: risk puanına "Disiplin (dönem)" faktörü (≤10, `StudentInsights`), pano "Dikkat gerektirenler"e iki öğe, otomatik yoklama uzaklaştırma
  günlerinde GELMEDİ yerine ayardaki durum (varsayılan İzinli) + "Disiplin: geçici uzaklaştırma (YPT-…)" notu yazar ve veli "gelmedi" olayı üretmez;
  elle yoklamada öğrenci "Uzaklaştırmada" rozetiyle, varsayılan İzinli + notla gelir (`SuspensionCalendar`). Öğretmen portalı yoklama ekranına bağlanmadı.
- Portal uçları `routes/api/discipline.php` sonunda, `withoutMiddleware('staff')`: `GET portal/discipline` (yalnız sonuçlanmış + portala açık yaptırımlar,
  savunma istemleri; olay ayrıntısı/puan/karar notu dönmez), `POST portal/discipline/defenses/{id}` (yalnız öğrenci hesabı), `GET teacher-portal/discipline/options|incidents`,
  `POST teacher-portal/discipline/incidents` (TeacherScope; karar yetkililerine uygulama içi bildirim). Portal ön yüzleri henüz bu uçları kullanmıyor.
- Yetkiler `discipline.view/create/decide/board/settings/export`: yonetici+mudur hepsi, rehber view+create (savunma dahil), danisman view; ogretmen yalnız portal.
- Testler: `tests/Unit/DisciplineRulesTest.php`, `tests/Unit/DisciplineFlowTest.php` (bellek içi SQLite, asıl migration ile). Yedek: `/home/oritoriu/_backups_kurs/disiplin-20260917/`.

## Finans genişletmesi: fatura, muhasebeleşme, tahsilat, belgeler (2026-09-17)
- **Fatura / e-Arşiv taslağı** (`/finans/faturalar`, `App\Services\Invoicing`): taslak → kesildi → iptal; kesilen fatura model düzeyinde değiştirilemez (düzeltme = iptal ya da iade faturası).
  - Numara yalnız kesimde verilir (GİB biçimi, ör. `EBE2026000000001`; iade `EBI…`). Fatura tarihi son kesilen faturadan önce olamaz.
  - KDV hesabı `InvoiceMath` ile yapılır (KDV dahil/hariç, iskonto, tevkifat n/10, kalem bazında yarım-yukarı yuvarlama).
  - Faturalanmamış tahsilatlardan toplu taslak (makbuz ya da öğrenci başına), kayıttan taslak, sonradan tahsilat bağlama.
  - **Entegratör bağlı değil:** `Integrators/InvoiceIntegrator` arayüzü, `NullIntegrator` ve `SimulationIntegrator` var; gerçek gönderim yok.
  - KDV oranları, önekler ve senet metni ayarlardan gelir: `/finans/ayarlar`, ayar grubu `accounting`.
- **Muhasebeleşme** (`App\Services\Accounting`, `/finans/muhasebe`):
  - TDHP'ye uygun sade hesap planı ve eşlemeler (kasa/banka/POS hesapları, gelir-gider kategorileri, sistem rolleri).
  - `AccountingObserver`, tahsilat, gelir/gider ve transferlerden aynı transaction içinde otomatik yevmiye fişi keser; fatura, iade ve mahsup fişlerini servisler keser.
  - Borç = alacak kuralı üç yerde korunur: servis (bcmath), model kancası ve MariaDB CHECK. İdempotentlik: aynı kaynak olayı için ikinci fiş oluşmaz.
  - İptal edilen belgenin fişi ters fişle kapanır. Elle fiş ve ters çevirme mümkün.
  - Ekranlar: yevmiye, büyük defter/muavin, mizan (Excel). Dönem kilidi `accounting_periods`: kapalı aya hiçbir işlem yazılamaz.
  - Hesap mantığı: tahsilat B kasa / A 340 → fatura B 340 (+120) / A 600 + 391 → iade B 340/120 / A kasa; POS komisyonu 653; sayım farkı 649/659; açılış 500.
  - Geriye dönük fiş komutu: `php artisan kurs:accounting-backfill [--dry-run]`. İdempotenttir; 17.09'da 349 fiş üretildi ve mizan dengede.
- **Tahsilat eklentileri:**
  - Fazla ödeme sonraki taksitlere aktarılır ya da avans olarak kalır (`overpayment`); avans mahsubu (`apply-credit`).
  - Veli toplu tahsilat (`/finans/tahsilat/veli`, çocuk başına makbuz, hepsi ya da hiçbiri).
  - İade (`refunds` + `refund_allocations`): önce avanstan düşülür, sonra en geç vadeli taksitten geri alınır; iade makbuzu PDF.
  - Kart/POS komisyonu ve beklenen yatış tarihi (`payment_card_details`).
  - Mutabakat (`/finans/mutabakat`): POS yatışı eşleştirme (transfer + komisyon gideri) ve banka hareketi işaretleme.
  - Gecikme takibi (`/finans/takip`): yaşlandırma, ödeme sözü, sorumlu, hatırlatma TASLAĞI (gönderim yok).
  - Cari hesap ekstresi (öğrenci/veli PDF).
- **Belgeler:**
  - Tek şablon ailesi `resources/views/pdf/finance/document.blade.php` + `parts/*`: makbuz (toplu), fatura (toplu), iade makbuzu, işlem dekontu (tahsilat/iade/gelir-gider/virman), ekstre, analiz raporu.
  - Senet (bono) `notes.blade.php`: taksit başına, A4'e 3/2 ya da A5 tek. `promissory_notes` tablosu basım sayısını tutar; tekrar basımda "SURETİDİR" yazar.
  - Senet üzerinde ödenmiş taksit ÖDENDİ, iptal taksit İPTAL olarak işaretlenir. Tutar değişirse eski senet iptal edilir, yeni numara verilir.
  - Basım yerleri: `/finans/senetler` (toplu), kayıt sayfası, alacaklar listesi.
- **Raporlar:** Rapor Merkezi'ne `/raporlar/finans-analiz` eklendi (`FinanceAnalytics`). İçerik: gelir tablosu (nakit ve fatura esaslı), nakit akışı, tahsilat performansı, yaşlandırma, fatura/KDV özeti, program kârlılığı (dağıtım anahtarları sayfada yazılı), mizan. Excel ve PDF çıktısı var; grafikler tek eksenli.
- **Kokpit ve uyarılar:**
  - `/finans` kutuları: bugünkü tahsilat, bekleyen, geciken, kasa/banka, faturalanmamış, mutabakat. Altında iş listesi şeridi.
  - Genel Bakış'ta günlük gecikme bandı: `GET finance/overdue-alert`, "bugün gizle" özelliği var.
  - Portal ana sayfasında veli/öğrenci gecikme uyarısı: `GET portal/finance/overdue-alert`; açılıp kapanması `portal.show_*_overdue_alert` ayarıyla. Portalda ekstre PDF: `portal/finance/statement.pdf`.
- **Yetkiler:** `finance.invoice`, `finance.refund`, `finance.reconcile`, `finance.collections`, `finance.accounting`, `finance.period_close`. Yönetici, müdür ve muhasebe rollerinde; danışmanda yok.
- **Migration'lar:** `2026_09_17_800100` (muhasebe/fatura/iade/mutabakat/takip + yetkiler), `2026_09_17_800200` (senet).
- **Rotalar:** `routes/api/finance-accounting.php`. Testler: `tests/Unit/FinanceAccountingTest.php`.
- **Demo eklemeleri:** 5 fatura (3 kesildi, 1 iptal, 1 taslak) + 1 kurum faturası + 1 iade faturası, 1 iade (500 ₺), 1 POS yatışı (20 işlem), 4 takip notu, 18 banka eşleşmesi, 17 senet.
- **Açık:**
  - Kesin KDV oranı, hesap kodları ve senet metni muhasebeci/hukukçu teyidi bekliyor.
  - Gerçek e-Arşiv entegratörü yok.
  - Fatura vadesi alanı yok; `invoice_due_days` ayarı kullanılıyor.

## Toplu e-posta / SMS gönderimi (2026-09-17)

- **Ekranlar:** `/iletisim/toplu-gonderim` (liste), `/yeni` ve `/:id/duzenle` (hazırlama + önizleme + onay), `/:id` (ilerleme, alıcı bazında durum, yeniden dene, iptal, kopyala), `/iletisim/ileti-izinleri` (ticari ileti onayı + ret listesi), `/ayarlar/mesaj-kanallari` (WhatsApp/SMS/E-posta durum kartları, SMS sağlayıcı ve SMTP), `/raporlar/iletisim`.
- **SMS sürücüleri** (`app/Services/Messaging/Sms/Drivers`): NetGSM (REST v2), Mutlucell (XML), VatanSMS (JSON v1), Simülasyon. Ortak arayüz `SmsGateway`: toplu gönderim, bakiye, teslim raporu, başlık listesi. Bağlantı testi = bakiye sorgusu. Sağlayıcı alan adları "sağlayıcıyla teyit" bekliyor (sınıf başlıklarında yazılı).
- **SMS uzunluğu:** `SmsLength` (PHP) + `smsLength.ts` aynı kural: Türkçe 155/150, Unicode 70/67, Türkçesiz 160/153; genişletme karakteri 2 sayılır.
- **E-posta:** kuruma özel SMTP (parola şifreli, `integrations.config_encrypted`), kurum logolu HTML şablon `resources/views/emails/campaign.blade.php`, `List-Unsubscribe` + tek tık. "Test e-postası gönder" yalnız kullanıcı adres yazınca.
- **Abonelikten çıkma:** imzalı jeton `UnsubscribeToken`, herkese açık `GET/POST /api/v1/abonelik/{token}` (GET yalnız onay sayfası). Adres `communication_suppressions`'a, kişi biliniyorsa ticari onay `false` olarak yazılır.
- **İzin kuralları (`CampaignPlanner`):** ticari → yalnız kanalda `purpose=marketing, granted=1` olanlar (elle eklenenler atlanır); duyuru → yalnız açıkça reddeden atlanır; ret listesi her toplu gönderimde atlanır; aynı adres kanal başına bir kez. Ticari SMS'e ret metni (RET numarası + isteğe bağlı kod/MERSİS) eklenir; ticari SMS için İYS marka kodu ve RET numarası zorunlu (simülasyon hariç).
- **Kuyruk:** onayda alıcı listesi `message_campaign_recipients`'a dondurulur; `kurs:campaigns-run` (her dakika) zamanı gelenleri başlatır ve `ProcessMessageCampaign` işini atar; `CampaignSender` dakikalık sınırla (`rate_per_minute`, varsayılan SMS 300 / e-posta 30) parça parça gönderir, her alıcı için `outbound_messages` satırı yazar. `kurs:campaigns-sync-reports` (10 dk) SMS teslim raporunu çeker. Kanal bağlı değilse bekleyenler açıklamalı başarısız olur, "yeniden dene" ile döner.
- **Yetkiler:** `messages.campaign` (yönetici, müdür, danışman), `messages.campaign_send` (yönetici, müdür), `messages.consents` (yönetici, müdür, danışman), `integrations.sms` / `integrations.email` (yönetici, sistem). `integrations.manage` her iki kanal ayarını da açar.
- **Migration:** `2026_09_17_900100_create_message_campaign_tables` (message_campaigns, message_campaign_recipients, communication_suppressions; outbound_messages.campaign_id/sms_parts/is_commercial; yetkiler).
- **Rotalar:** `routes/api/campaigns.php`, `routes/api-public/campaigns.php`, `routes/schedules/campaigns.php`. Testler: `tests/Unit/MessageCampaignDriversTest.php`, `tests/Unit/MessageCampaignFlowTest.php` (toplam 325 yeşil).
- **Canlı durum:** SMS ve e-posta **bağlı değil** (entegrasyon satırı yok). Demo için simülasyonla 2 gönderim yapıldı (#1 veli toplantısı 114 SMS, #2 öğretmen/personel 20 SMS; "simülasyon" rozetli); ardından simülasyon ayarları silindi.
- **Açık:** gerçek sağlayıcı alan adlarının teyidi; İYS'ye onay aktarımı (sağlayıcı/İYS paneli); SMS "RET" yanıtlarının sağlayıcıdan otomatik çekilmesi yok (ret listesine elle eklenir); ileti metinlerinin hukuki teyidi; veli/öğrenci formunda ticari onay kutusu yok (İleti izinleri ekranından toplu kaydediliyor).

## Açık kalan işlerin tamamlanması (2026-09-17 öğleden sonra)

- **Veli talebi bildirimi:** `App\Services\Portal\ContactRequestNotifier`. Talep açılınca öğretmen hesabına, öğretmen yanıtlayınca ya da kapatınca veli hesabına **yalnız uygulama içi** bildirim gider (`app_notifications` + Expo push denemesi). SMS/WhatsApp gönderilmez. Aynı talep için bildirim bir kez yazılır (`data.once`). Bildirim hatası talebi bozmaz. Bağlantı yerleri: `PortalExtrasController::requestStore`, `TeacherPortalController::requestRespond`.
- **Gözlemler (yönetim):** `GET students/{student}/observations` (`Api/Students/StudentObservationController`, students.view, salt okunur). StudentDetail'e "Gözlemler" sekmesi eklendi: özet, tür filtresi, öğretmen/ders/sınıf ve görünürlük rozetleri. Sayı `counts.observations` alanında.
- **Öğrenci profili › Ödemeler:** "Belgeler" şeridi eklendi. İçinde hesap ekstresi PDF, senet yazdırma (`NotePrintDialog`, `student_id` seçicisi), tüm faturalar ve yeni fatura taslağı (`/finans/faturalar/yeni?ogrenci=`) var. Altında son 8 faturanın listesi yer alır.
- **Portal ayarları:** Ayarlar › Kurum › **Portal** sekmesi (`?sekme=portal`). Sekmede şunlar toplandı:
  - `portal.guardian_requests_enabled`
  - `portal.show_student_overdue_alert` / `portal.show_guardian_overdue_alert` (Finans ayarlarıyla aynı anahtar)
  - disiplin portal anahtarları: `portal_enabled`, `portal_defense_requests`, `portal_defense_submission`, `teacher_portal_reporting`. Bunlar `PUT discipline/settings` ile yazılır.

  `settings/portal` grubu kuralları `sometimes` yapıldı.
- **Duyurular:** Sınıf ya da program hedefli duyurular, seçili çocuğun velisine de görünür (`AnnouncementAudience::forGuardian($branch, $groupIds, $programIds)`). Yeni `audience.students_only` seçeneği (formda "Yalnız öğrencilere") işaretliyse veli görmez. "Uygulama bildirimi" kanalı seçiliyse bildirim alan velilerin aktif hesaplarına da uygulama içi bildirim düşer; SMS/WhatsApp alıcı listesi değişmedi. Liste rozeti: "+ veliler" / "yalnız öğrenciler". "Tüm öğrenciler" hedefli duyurular veliye hâlâ görünmüyor.
- **Disiplin:** Veli bildirimi taslağında telefon `0532 123 45 67` biçiminde gösteriliyor. İleti izinleri listesi de aynı biçime geçti. Demo zaman çizelgesi düzeltildi: 17.09 13:33:11 damgalı 182 zaman alanı olay/savunma/kurul/yaptırım sırasına çekildi (yalnız zaman alanları). Önceki haller `_backups_kurs/kalanlar-20260917/*-oncesi.tsv` dosyalarında, uygulanan liste `disiplin-zaman-uygulandi.json` dosyasında.
- **Gecikme takibi:** Yeni `promise=today|late` filtreleri. Kutular artık doğru filtreyi açıyor ve listeyle aynı ölçüyü kullanıyor: vadesi geçmiş borcu olan öğrenci sayısı, öğrencinin en erken açık sözü esas.
- **İleti izinleri:** "Bu grupta onaylı" sayacı şubeye ve listedeki durum filtresine göre süzülüyor.
- **Ticari ileti onayı (formlar):** Öğrenci formunda (öğrenci + her veli) ve veli düzenleme formunda SMS / e-posta / WhatsApp kutuları var; varsayılan kapalı. `ConsentService::syncFromForm` yalnız değişen kanalı "Kayıt formu" kaynağıyla yazar. `marketingState()` ile okunur (`students/{id}` → `student.marketing_consents`, `guardians[].marketing_consents`). Veli detayında onay rozetleri var. WhatsApp bilgilendirme izni sorgusu artık `purpose != marketing` ile süzülüyor.
- **Mükerrer veli birleştirme:** `App\Services\Guardians\GuardianMergeService`.
  - Uçlar: `GET guardians-duplicates` (guardians.view), `POST guardians-merge/preview` ve `POST guardians-merge` (guardians.manage). Birleştirme `fingerprint` + `confirm` ister; önizleme bayatsa 409 döner. TC kimlik numaraları farklıysa birleştirme engellenir.
  - Telefon anahtarı son 10 hanedir; telefon ya da WhatsApp numarası eşleşirse aynı grup sayılır.
  - Taşınanlar: öğrenci bağları (çakışırsa işaretler birleşir), `enrollments.financial_guardian_id`, `payments.guardian_id`, `promissory_notes.guardian_id`, `collection_notes`, `contact_requests`, `invoices(buyer_type=guardian)`, `journal_lines(partner_type=guardian)`, `outbound_messages`, `message_campaign_recipients`, `communication_suppressions`, görev/belge/etkinlik akışı, etiketler, ileti izinleri (daha yeni kayıt geçerli), boş iletişim alanları.
  - Belge üzerindeki adlar (fatura alıcısı, senet borçlusu) değişmez.
  - Portal hesabı: hedefin hesabı yoksa kaynağınki taşınır. İkisinde de varsa kaynağın hesabı kapatılır, talepleri ve bildirimleri hedef hesaba geçer.
  - İşlem tek transaction içinde yapılır. Kaynak soft delete edilir. Denetim kayıtları: `guardian.merged` (ayrıntılı) + `guardian.merged_away`.
  - Ekranlar: Veliler listesinde "Olası mükerrer" rozeti, filtre ve "Mükerrer veliler (N)" düğmesi; veli detayında uyarı; `/veliler/birlestir` (grup → kalacak/birleşecek → önizleme → onay). Canlıda şu an mükerrer veli yok.
- **Rotalar:** `routes/api/student-extras.php`. Migration yok.
- **Testler:** `GuardianMergeTest` (8), `ContactRequestNotificationTest` (5), `MarketingConsentFormTest` (2). Bu işin testleri yeşil. Tam koşuda yalnız eşitleme ajanının yazmakta olduğu `SyncInfrastructureTest` içindeki 2 test kırmızı (bu işle ilgisiz).
- **Portal ajanına kalan ön yüz işleri:**
  - Öğrenci/veli portalında ve öğretmen portalında bildirim zili yok; `GET /notifications` uçları portal hesaplarına açık. Bildirim bağlantıları: `/ogretmen/talepler`, `/portal/ogretmenler`, `/portal/duyurular`.
  - Portal duyuru listesinde sınıf/program duyurusu için "Sınıfınıza" gibi bir hedef etiketi gösterilebilir.
- **Yedek:** `_backups_kurs/kalanlar-20260917/`. DB yedeği: `backups/manual-2026-09-17_150254.sql.gz`.

## Çevrimdışı yerel kurulum ↔ web eşitleme altyapısı (2026-09-17)
- Ayrıntı: `docs/SYNC.md`. Kod: `app/Sync/*` (SyncRegistry, ChangeRecorder, Sweeper, RowCodec, SyncNumbers, RecomputeService,
  Server/*, Local/*, Commands/*), `app/Providers/SyncServiceProvider.php`, `config/kurs.php` (`KURS_NODE=server|local`, `KURS_DATA_KEY`),
  `config/sync.php`, `routes/api/sync.php`, `routes/api-public/sync.php`, `routes/schedules/sync.php`.
- Migration `2026_09_17_950100_create_sync_tables` (yalnız --path ile çalıştırıldı): sync_devices/changes/receipts/conflicts/row_hashes/
  table_state/number_blocks/tombstones/state/deferred + 85 tabloya `uuid` (kayıt defterinden) + yetkiler `sync.use`, `sync.manage`.
- **Yeni tablo ekleyen migration → `app/Sync/SyncRegistry.php`'ye ekle + `php artisan kurs:sync-prepare`** (test tanımsız tabloyu yakalar).
- Ön yüz modülü `sync` (yayın listesinde): `/ayarlar/bagli-cihazlar`, `/ayarlar/esitleme-cakismalari`; yerel kurulumda üst çubuk göstergesi.
- Finans çevrimdışı yalnız komutla: tahsilat/iptal/kart ayrıntısı/kayıt+plan, **iade/iptal, gelir-gider/iptal, hesap aktarımı/iptal,
  POS yatışı/iptal, senet hazırlama + basım kaydı (toplu), tahsilat takip notu, ödeme planı düzenleme, indirim/burs** (aynı uuid +
  belge no ile web'de yeniden yürütülür; çift işlemde mutabakat). Fatura, hesap/açılış/sayım farkı, ekstre işaretleme yerelde engelli.
- **Eksikler tamamlandı (17.09 akşam, sürüm 1.9.2):** dosya eşitlemesi (`app/Sync/Files/*`, `sync/files/*`, `LocalFileSync`,
  `kurs:sync-files`), `kurs:data-key status|generate|migrate|rollback` (canlıda yalnız dry-run: 407 alan; `.env`'ye anahtar
  YAZILMADI — adımlar docs/SYNC.md › Güvenlik, kullanıcı onayıyla), `StartSessionUnlessBearer` (Bearer isteği oturum açmaz),
  yerelde personel hesabı yazımı engelli + parola yalnız çevrimiçi (`sync/password`), SQLite çift tırnak uyumu, çakışma ekranı
  finans metni + dar ekran. Migration `2026_09_17_960100_create_sync_files_and_key_backups` (yalnız --path ile çalıştı).
  Uçtan uca (geçici yerel kopya + yalıtılmış veri anahtarlı deneme sunucusu) geçti; canlı ZZTEST verisi temizlendi, sapma 0.
- Açık: masaüstü/mobil paketleme; `KURS_DATA_KEY` üretimi + taşıma (onay bekliyor); `APP_ENV=local` katı kipte finans
  listeleri/rehberlik/etüt ayrıntısı seçilmemiş sütuna erişiyor (üretimde sessiz null — düzeltilmeli).
- Testler: `tests/Unit/SyncInfrastructureTest.php` (18), `tests/Unit/SyncGapsTest.php` (13). Yedek: `/home/oritoriu/_backups_kurs/esitleme-20260917/`,
  `/home/oritoriu/_backups_kurs/esitleme-eksikler-20260917/`.

## Form ve tablo alan denetimi (2026-09-17, sürüm 1.9.1)
- **Kök hata:** `lang/` dizini yoktu → özel mesajsız her doğrulama hatası kullanıcıya "validation.required" diye gidiyordu. `lang/tr/{validation,auth,passwords,pagination}.php` eklendi (Türkçe kurallar, joker destekli geniş `attributes`, `guardians.*` için `custom`). **Yeni alan eklerken Türkçe adını ya buraya ya da `validate($rules, [], [$attributes])` 3. argümanına yaz** (yoksa "interested program id" gibi İngilizce görünür).
- Finans: `Api/Finance/FinanceValidation.php` + `FinanceController::validateTr()` (55 çağrı). Disiplin: `DisciplinePresenter` iletileri. Diğer uçlar (öğretmen, personel, duyuru, mesaj, şablon, otomasyon, kampanya, kullanıcı, kurum, akademik, yoklama, rehberlik, ödev…) 3. argümanla. Ödev: sınıf ya da öğrenci seçimi artık zorunlu; duyuru/mesaj: hedef sınıf/program `required_if`.
- **Ortak bileşenler:** `Field optional` (etikete "(isteğe bağlı)"); `contact.tsx` maskeli numara (•) bağlantısız + `AddressText`; Badge 12px, Tabs sayacı 12px, tablo başlığı 11.5px, mobil kart etiketi 12px; DataTable mobil kart `min-w-0` + işlem alanı `max-w-[60%] flex-wrap`, araç çubuğu `min-w-0` (390px'te sağdan kırpılma buydu); `NotificationCenter` `mapUrl` + mobilde zilin altında sabit panel + `announcement` türü.
- **Hizalama kuralı (kullanıcı):** DataTable'da ilk sütun + kişi/iletişim sütunları (anahtarda phone/email/guardian/contact/payer/buyer/`student` ya da `align:'left'`) sola, işlem sütunu sağa, geri kalan her şey ortada (`align:'right'` artık ortalar). Özel tablolar da aynı kurala çekildi.
- **Formlar:** "* zorunlu, diğerleri isteğe bağlı" notu; öğrenci formunda gönderim öncesi yerel eksik kontrolü (veli adı/soyadı/telefonu, kayıt dönemi/program/fiyat/taksit/vade), dönem varsayılanı yoksa ilk dönem; kayıt oluşturmada "Doldurulması gereken: …" listesi; disiplin olay formunda hata varsa ayrıntı bölümü açılır.
- **Borç gösterimi:** veli listesindeki "Gecikmiş ödemesi olan" süzgeci kaldırıldı. Pano yalnız toplam (finans yetkisiyle) gösteriyor.
- **Portal:** öğrenci/veli ve öğretmen üst çubuğunda bildirim zili (yönetim adresli bildirimler portal karşılığına çevrilir, yoksa yalnız okundu işaretlenir); ayrıntı sayfaları (`PortalHomeworkDetail`, `TeacherClassDetail`, `TeacherStudent`, `TeacherAttendanceTake`, `TeacherHomeworkDetail`) `usePortalPageTitle` ile üst çubukta kendi başlığını gösterir.
- Terim birliği: "parola" → "şifre"; "No X" → "Öğrenci no X"; D/Y/B → "Doğru / Yanlış / Boş".
- Açık: program botu/sınıf yapısı/optik içe aktarma uçlarında Türkçe ad eksikleri; risk yüzdesi biçimi tutarsız (`StudentInsights`); navigation'da "Mesaj Şablonları", "Rehberlik Görüşmeleri" büyük harf, `/iletisim/whatsapp` SMS'i de listeliyor (menü adı); `/finans/takip`te menüde "Özet" etkin; MessagingChannels sağlayıcı alanları işaretsiz.
- Testler 358/358. Yedek: `/home/oritoriu/_backups_kurs/alan-denetimi-20260917/`.
