# Biyometrik Yoklama Terminali Köprüsü (Perkotek YT-33 / ZKTeco)

Öğrenci giriş/çıkışını parmak izi terminalinden okuyup sisteme aktaran katman.

## Neden köprü?

Cihaz kurumun **yerel ağındadır** (wifi/kablo), web sunucusu ise internettedir. Sunucu cihaza
ulaşamaz. Bu yüzden cihaza **Mac masaüstü uygulaması** bağlanır (`KURS_NODE=local`), kayıtları
yerel veritabanına yazar; internet geldiğinde mevcut eşitleme (`kurs:sync`) bunları web'e taşır.

```
[Terminal 192.168.1.x:4370]  ←LAN→  [Mac masaüstü: kurs:cihaz-cek]  →yerel DB→  [kurs:sync]  →internet→  [kurs.bogahostdeveloper.com.tr]
```

Köprü saf PHP'dir (`stream_socket_client`), ek PHP eklentisi gerekmez; aynı kod sunucuda da çalışır
(cihaz sunucudan erişilebilir bir ağda olsaydı).

---

> **Kurulumdan önce:** `2026_09_17_970100_add_zk_bridge_columns_to_devices` migration'ı çalışmalıdır
> (`devices` tablosuna 11 yeni sütun ekler; yalnız ekleme yapar, geri alınabilir). Çalışmadan
> "Terminal Köprüsü" sekmesi hata verir.

## 1. Cihazda yapılacak ayarlar

Terminalin menüsünde (model/yazılıma göre adlar biraz değişebilir):

| Menü | Ayar | Değer |
|---|---|---|
| **Comm (İletişim) > Ethernet** | IP Adresi | Sabit bir IP verin, ör. `192.168.1.50` |
| | Alt ağ maskesi | Genelde `255.255.255.0` |
| | Ağ geçidi | Modeminizin IP'si, ör. `192.168.1.1` |
| | DHCP | **Kapalı** (IP değişirse köprü cihazı bulamaz) |
| **Comm > PC Bağlantısı / Bağlantı** | Port | `4370` (fabrika değeri — değiştirmeyin) |
| | İletişim Şifresi (Comm Key) | `0` (kapalı) ya da 1–999999 arası bir sayı |
| **Sistem > Tarih/Saat** | Saat | Bilgisayarla aynı olmalı |

> **İletişim şifresi (comm key)** cihaza kimlerin bağlanabileceğini belirler. `0` ise şifre
> sorulmaz. Bir değer verdiyseniz aynı değeri uygulamada da girmelisiniz; yanlışsa cihaz
> bağlantıyı "kimlik reddedildi" diye kapatır.

**Cihazın IP'sini öğrenme:** Menü > Sistem Bilgisi / Ağ Bilgisi ekranında yazar.

### Ağ koşulları

- Mac ile cihaz **aynı yerel ağda** olmalı (aynı modem/wifi). Misafir ağı ayrı bir ağdır, çalışmaz.
- Mac'in güvenlik duvarı giden **4370/TCP** bağlantısına izin vermeli.
- Cihaz aynı anda genelde **tek bağlantı** kabul eder. Perkotek/ZKTeco'nun kendi Windows programı
  açıksa kapatın, yoksa köprü "cihaz bağlantıyı kapattı" hatası alır.

---

## 2. Uygulamada yapılacaklar

1. **Cihaz kaydı:** Yoklama > Cihazlar ekranından cihazı ekleyin (tür: `fingerprint`).
   Ardından IP, port ve iletişim şifresini girin (`PUT /api/v1/attendance/zk/devices/{id}/baglanti`).
   İletişim şifresi veritabanında **şifreli** saklanır, ekranda bir daha gösterilmez.
2. **Bağlantı testi:**
   ```bash
   php artisan kurs:cihaz-test 192.168.1.50 --port=4370 --sifre=0
   ```
   Çıktıda seri no, yazılım sürümü, kullanıcı/kayıt sayısı ve son 5 okutma görünür.
   Hata alırsanız çıktıdaki **Öneri** satırı ne yapmanız gerektiğini yazar.
3. **Kullanıcı eşlemesi:** Cihazda her öğrenciye bir **kullanıcı numarası** verilir (parmak izi
   kaydı sırasında). Bu numarayı öğrenciyle eşleştirin:
   ```bash
   php artisan kurs:cihaz-kullanicilar --device=3
   ```
   Komut cihazdaki kullanıcıları listeler ve ada göre öğrenci önerisi yapar. Eşlemeyi ekrandan
   ya da `POST /api/v1/attendance/zk/eslemeler` ile onaylayın.
4. **Kayıt çekme:**
   ```bash
   php artisan kurs:cihaz-cek --device=3          # yalnız yeni kayıtlar
   php artisan kurs:cihaz-cek --device=3 --tam    # cihazdaki tüm kayıtlar (ilk kurulum)
   ```
   Masaüstü uygulamasında bu komut zamanlayıcıdan düzenli çalışır.

---

## 2b. Ekran: Yoklama > Cihazlar > **Terminal Köprüsü**

Üç bölüm:

1. **Bağlantı** — IP, port, bağlantı türü (TCP/UDP) ve iletişim şifresi. "Bağlantıyı test et"
   cihaz künyesini (seri no, yazılım, kayıt sayısı, cihaz saati) ve son okutmaları gösterir;
   ulaşılamazsa ne yapılacağını yazar. Yanda **köprü durumu**: son çekme, sonuç, imleç, son hata
   ve "Yeni kayıtları çek" / "Tümünü çek" düğmeleri.
2. **Cihaz kullanıcıları** — cihazdaki kullanıcı numaraları, adları ve ada göre **öğrenci önerisi**.
   Öneriye tıklayarak ya da öğrenci arayarak eşleme yapılır. Otomatik eşleme YOKTUR: yanlış eşleme
   yanlış velinin telefonuna bildirim gönderir.
3. **Bekleyen okutmalar** — hiçbir öğrenciye bağlanamamış okutmalar.

> Bu üç işlem cihaza LAN üzerinden bağlanır. **Web sunucusundan çalışmaz** (sunucu kurumun yerel
> ağına giremez); kurumdaki **masaüstü uygulamasından** yapılmalıdır. Ekran bunu açıkça söyler.

## 2c. Otomatik çekme (zamanlanmış)

Masaüstü uygulamasında (`KURS_NODE=local`) `kurs:cihaz-cek` **dakikada bir** çalışır
(`routes/schedules/devices.php`). Üst üste binmez (`withoutOverlapping` + cihaz başına `Cache::lock`),
cihaz kapalıysa sessizce geçer ve hatayı cihaz kaydına yazar (ekranda "Son çekmede hata" olarak görünür).
Web sunucusunda bu zamanlama **hiç kurulmaz**.

## 3. Veri nereye yazılır?

Ayrı bir yoklama sistemi **kurulmamıştır**; var olan yapı kullanılır:

| Veri | Tablo | Not |
|---|---|---|
| Cihaz bağlantı bilgileri | `devices` (`zk_*` sütunları) | İletişim şifresi şifreli; düğümler arasında taşınmaz |
| Öğrenci ↔ cihaz kullanıcı no | `device_identities` | `kind = fingerprint`, `identifier = cihaz kullanıcı no` |
| Ham okutmalar | `attendance_events` | `idempotency_key = zk:{cihaz}:{kullanıcı}:{zaman}` → aynı kayıt iki kez işlenmez |
| Günlük özet | `daily_presences` | İlk giriş / son çıkış / içeride mi |

**Eşleşmeyen okutmalar kaybolmaz:** cihaz kullanıcı numarası hiçbir öğrenciye bağlı değilse kayıt
`attendance_events` içine `is_matched = false` ile yazılır ve Canlı Giriş/Çıkış ekranındaki
"Eşleşmemiş okutmalar" listesinde bekler; oradan öğrenciye bağlandığında eşleme de otomatik oluşur.

---

## 4. Sorun giderme

| Belirti | Olası neden | Çözüm |
|---|---|---|
| `Cihaza bağlanılamadı` | Cihaz kapalı, IP yanlış/değişmiş, farklı ağ | Cihaz menüsünden IP'yi doğrulayın; Mac'ten `ping 192.168.1.50` deneyin; DHCP'yi kapatıp sabit IP verin |
| `Cihaz yanıt vermedi (süre doldu)` | Cihaz meşgul, UDP paketi kayboldu | Birkaç saniye sonra tekrar deneyin; `--aktarim=udp` ile deneyin |
| `İletişim şifresi reddedildi` | Comm key yanlış | Cihaz menüsü Comm > İletişim Şifresi değerini okuyup aynısını girin (kapalıysa `0`) |
| `Cihaz bağlantıyı kapattı` | Başka program bağlı | Perkotek/ZKTeco PC yazılımını kapatın |
| `Sağlama (checksum) hatası` | Farklı protokol sürümü / ağ bozulması | `--aktarim=udp` deneyin; sürmezse cihaz ADMS moduna geçirilebilir (aşağıya bakın) |
| Bağlantı iyi ama kayıt gelmiyor | Cihaz kullanıcıları eşlenmemiş | `kurs:cihaz-kullanicilar` ile eşlemeleri tamamlayın; eşleşmemiş okutmalar ekranını kontrol edin |
| Saatler kayık | Cihaz saati yanlış | `kurs:cihaz-test ... --saati-esitle` ile cihaz saatini bilgisayara eşitleyin |

### Kayıtlar tekrar çekilsin mi?

İmleç (`devices.zk_cursor_at`) son işlenen kaydın zamanını tutar. `--tam` ile imleç yok sayılır ve
cihazdaki tüm kayıtlar okunur; zaten işlenmiş olanlar `idempotency_key` sayesinde **yeniden
yazılmaz** (yinelenen olarak sayılır). Bu yüzden `--tam` her zaman güvenlidir.

---

## 5. Yedek yol: ADMS / iclock (bu sürümde YOK)

Bazı yeni aygıt yazılımları 4370 portunu kapatıp yalnız **ADMS** (push SDK) konuşur: cihaz kendisi
bir HTTP sunucusuna `POST /iclock/cdata` ile kayıt gönderir. Bu durumda köprü yön değiştirir
(cihaz → bize). Planlanan uçlar: `GET /iclock/cdata` (el sıkışma), `POST /iclock/cdata`
(attlog satırları), `GET /iclock/getrequest` (komut kuyruğu). Bu sürümde **uygulanmadı**;
`devices.protocol` sütunu `'zk'` / `'adms'` ayrımını şimdiden taşıyabilir.
