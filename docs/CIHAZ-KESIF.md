# Ağda Cihaz Bulma, Sağlayıcı Sürücüleri ve Cihaz Teşhisi

Bu belge `docs/CIHAZ-KOPRUSU.md`'nin üstüne inşa edilmiştir. Köprü "cihazın IP'sini biliyorsan
bağlan" der; bu belge "IP'yi hiç bilmesen de bul, tanı, tek tıkla ekle, sorunu söyle" kısmıdır.

---

## 1. Neden gerekliydi?

Eskiden cihaz eklemek için kullanıcının şunları bilmesi gerekiyordu: cihazın IP adresi, portu,
bağlantı türü ve — entegrasyonlar sayfasında — bunları **ham JSON** olarak yazmak. Bu, kurum
personelinden beklenemeyecek bir şeydi.

Şimdi üç yol var:

| Yol | Ne zaman |
|---|---|
| **Ağda ara** | Cihazın IP'si bilinmiyor (olağan durum) |
| **IP ile ekle** | IP biliniyor ya da farklı bir alt ağda |
| **ADMS (cihaz kendisi gelir)** | Cihaz 4370'i kapatıyor ya da farklı ağda |

---

## 2. Ağ taraması nasıl çalışır?

```
[Bu bilgisayarın ağ arayüzleri]          NetworkProbe
        ↓  yalnız ÖZEL ağlar (192.168 / 10.x / 172.16-31)
[192.168.1.0/24 → 254 adres]
        ↓  (a) UDP yayın keşfi (hızlı, yardımcı)     ZkBroadcast
        ↓  (b) TCP 4370 eşzamanlı süpürme (asıl yol)  PortSweep
[açık portlar]
        ↓  künye sorgusu (seri no, model, yazılım, sayaçlar)
[aday cihazlar] → kayıtlı mı? → "Ekle"
```

### (a) UDP yayın keşfi — yardımcı yol

Yayın adresine (ör. `192.168.1.255:4370`) normal bir `CMD_CONNECT` çerçevesi gönderilir; UDP
konuşan cihazlar kendi IP'lerinden yanıt verir.

> **Dürüst not:** pyzk ve node-zklib'de yayın taraması **yoktur**; ikisi de tek adrese bağlanır.
> Yayın, fabrikanın kendi arama aracının kullandığı ve toplulukta çalıştığı bilinen bir yoldur,
> ama **her aygıt yazılımında açık değildir** ve bazı ağ anahtarları yayını bastırır. Bu yüzden
> burada yalnız hızlandırıcıdır: hiç yanıt gelmese de tarama sonuç verir.

### (b) TCP süpürme — asıl yol

254 adresi sırayla denemek (her biri 300 ms) **76 saniye** sürerdi. Bunun yerine bütün
bağlantılar aynı anda açılır (`STREAM_CLIENT_ASYNC_CONNECT`) ve `stream_select` ile hangisinin
tamamlandığı beklenir. Bağlantı tamamlanınca soket "yazılabilir" olur; başarılı mı reddedildi mi
ayrımı `stream_socket_get_name($s, true)` ile yapılır.

**Ölçülen süre** (testte, 254 adres, 128'lik pencere): **0,13 saniye**. Üst sınır 15 saniyedir ve
her turda kontrol edilir — hiçbir çağrı asılı kalamaz.

### Güvenlik sınırları

- **Yalnız özel ağlar taranır.** `8.8.8.0/24` gibi genel bir blok istense bile boş liste döner.
  İnternet taraması yapılmaz.
- `/24`'ten geniş maske otomatik olarak `/24`'e daraltılır (65 bin adres taranmaz).
- Tarama `devices.manage` yetkisi ister ve denetim günlüğüne yazılır.

### Sunucuda çalıştırılırsa

Web sunucusunun yerel ağı yoktur (tek genel IP, `/32`). Ekran ve komut bunu **açıkça** söyler:

> Bu ekran WEB SUNUCUSUNDA açıldı. Sunucu kurumun yerel ağına giremez; parmak izi terminalleri
> ancak kurumdaki bilgisayarda kurulu masaüstü uygulamasından taranabilir.

### Komut satırından

```bash
php artisan kurs:cihaz-tara                      # bu makinenin ağlarını tara
php artisan kurs:cihaz-tara --ag=192.168.1.0/24  # belirli bir ağ
php artisan kurs:cihaz-tara --bekleme=500        # yavaş ağda adres başına 500 ms
php artisan kurs:cihaz-tara --kunye-yok          # yalnız açık portları listele (en hızlı)
php artisan kurs:cihaz-tara --json               # başka bir araca vermek için
```

---

## 3. Sağlayıcı sürücüleri (marka bağımsızlık)

`devices.protocol` sütunu hangi dille konuşulacağını söyler. Sürücüler
`app/Services/Devices/Drivers/` altındadır ve ekranın marka listesi **sunucudan** okunur
(`GET /api/v1/attendance/devices/protokoller`) — ön yüzde ikinci bir liste tutulmaz.

| Anahtar | Sürücü | Durum | Nasıl çalışır |
|---|---|---|---|
| `zk` | ZKTeco / Perkotek | **hazır** | Biz cihaza bağlanırız (TCP/UDP 4370) |
| `adms` | ADMS / iclock | **hazır** | Cihaz bize HTTP POST atar |
| `hikvision` | Hikvision | *yakında* | Yalnız arayüz; ISAPI katmanı yazılmadı |
| `anviz` | Anviz | *yakında* | Yalnız arayüz; TC/IP katmanı yazılmadı |

> "Yakında" olanlarda cihaz kaydı açılabilir ama kayıt çekilmez ve ekran bunu söyler. Yarım
> yazılmış bir protokol hiç olmamasından kötüdür: yanlış okunan bir giriş kaydı yanlış velinin
> telefonuna bildirim gönderir.

---

## 4. ADMS / iclock — cihaz kendisi gönderirse

Bazı yeni aygıt yazılımları 4370'i kapatıyor. O cihazlarda yön tersine döner: **cihaz bize
bağlanır.**

```
[Terminal]  --HTTP-->  [Kurumdaki bilgisayar :80 /iclock/...]  --> aynı yoklama boru hattı
```

### Uçlar (oturumsuz — terminalin jetonu yoktur)

| Uç | Ne yapar |
|---|---|
| `GET /iclock/cdata?SN=…&options=all` | El sıkışma: cihaza ayar metni döner |
| `POST /iclock/cdata?SN=…&table=ATTLOG` | Okutmalar (TAB ayraçlı satırlar) |
| `GET /iclock/getrequest?SN=…` | Cihaz komut sorar; nabız olarak kullanılır |
| `POST /iclock/devicecmd?SN=…` | Komut sonucu bildirimi |

### Güvenlik (bilinçli sınırlar)

1. Uçlar `devices_adms.enabled` açıkken vardır — **varsayılan: yalnız yerel düğüm**
   (`KURS_NODE=local`). Web sunucusunda her zaman `OK` döner ve hiçbir şey yapmaz.
2. Kimlik **cihazın seri numarasıdır** ve seri no **önceden kayıtlı olmalıdır**
   (`devices.serial_no` + `protocol='adms'`). Tanınmayan seri no **hiçbir veri yazamaz**;
   yalnız "tanıtıldı ama ekli değil" listesine düşer, yönetici ekrandan onaylar.
3. Yanıtlar her zaman düz metindir ve hata durumunda bile `OK` benzeri kalır — cihaz JSON/HTML
   anlamaz, anlamadığında aynı kayıtları sonsuza kadar tekrar gönderir.
4. CSRF ve oturum ara katmanları bu uçlardan çıkarılmıştır (cihazda çerez/jeton yoktur;
   10 saniyede bir oturum satırı açmanın anlamı da yoktur).

### Aynı boru hattı, aynı idempotency

ADMS kayıtları da `PresenceService::ingest()` üzerinden geçer. Anahtar:
`adms:{cihaz}:{kullanıcı}:{unix zaman}` — ZKTeco köprüsündeki `zk:…` deseninin aynısı. Cihaz aynı
satırı tekrar gönderse de ikinci kez yazılmaz; **iki ayrı yoklama sistemi oluşmaz.**

### Cihaz menüsünde

```
Comm (İletişim) > ADMS / Sunucu Ayarları
  Sunucu adresi : kurumdaki bilgisayarın yerel IP'si (ekranda yazar)
  Port          : 80
  Biçim         : HTTP  (Proxy ve "DNS ile bağlan" kapalı)
```

---

## 5. Teşhis — "neden çalışmıyor?"

Her cihaz kartında: **bağlı mı · son kayıt ne zaman · kaç kayıt bekliyor · son hata + ÇÖZÜM.**

Teşhis **cihaza bağlanmaz**; yalnız veritabanındaki izlerden okur (son çekme sonucu, son olay,
eşleşmemiş kayıtlar). Bu yüzden ekran anında açılır ve web sunucusunda da çalışır — cihaza
ulaşamamak teşhisi engellemez, teşhisin kendisi zaten bunu söyler.

| Durum | Ne demek | Çözüm önerisi ne der |
|---|---|---|
| `kurulmadi` | IP/seri no girilmemiş, hiç konuşulmamış | "Ağda cihaz bul" ile taratın ya da IP'yi girin |
| `hata` | Son deneme başarısız | Hata koduna göre cihaz menüsünde ne yapılacağı |
| `uyari` | Bağlantı var ama 2 gündür kayıt yok / bekleyen eşleşmemiş kayıt var | Cihaz saati, eşleme ekranı |
| `ok` | Çalışıyor | — |

Hata kodundan çözüm üretimi (`DeviceDiagnostics::remedy`):

| Kod | Çözüm özeti |
|---|---|
| `kimlik` | İletişim şifresi yanlış → Comm > İletişim Şifresi |
| `baglanti` | 5 maddelik sıralı kontrol listesi (fiş, ağ, IP, aynı ağ, tek bağlantı) |
| `zaman_asimi` | Cihaz meşgul ya da başka program bağlı |
| `protokol` | TCP yerine UDP dene, portu doğrula |

---

## 6. Entegrasyonlar sayfası — ham JSON kaldırıldı

- Her sağlayıcının **alan şeması sunucudan gelir** (`GET /api/v1/integrations` → `fields`).
  Ön yüz kendi listesini tutmaz.
- Ham JSON yalnız **"Gelişmiş"** başlığı altında, **salt okunur özet** olarak kalır.
- **Biyometrik cihaz kartı kendi kaydını TUTMAZ.** `devices` tablosundan okur ve
  Yoklama › Cihazlar ekranına bağlanır. İki ayrı yerde iki ayrı cihaz kaydı oluşmaz.
- **Optik okuyucu kartı** yalnız varsayılan davranışı (ayraç, kodlama, eşleme ölçütü) tutar;
  kolon eşlemesi dosyadan dosyaya değiştiği için işin kendisi **optik okuma sihirbazındadır**
  (yükle → kolonları tanı → öğrenci eşleştir → önizle → aktar).

---

## 7. Uçlar

| Uç | Yetki | Ne yapar |
|---|---|---|
| `GET  attendance/devices/kesif/ortam` | `devices.manage` | Bu makine hangi ağda, tarama mümkün mü |
| `POST attendance/devices/kesif/tara` | `devices.manage` | Ağı tara |
| `POST attendance/devices/kesif/dene` | `devices.manage` | Tek IP'yi dene (elle giriş) |
| `POST attendance/devices/kesif/ekle` | `devices.manage` | Bulunanı kaydet (varsa günceller) |
| `GET  attendance/devices/protokoller` | `devices.manage` | Marka/protokol kataloğu + form alanları |
| `GET  attendance/devices/teshis` | `devices.manage` | Tüm cihazların teşhis satırları |

---

## 8. Veritabanı

`2026_09_18_970200_add_device_discovery_columns_to_devices` — **yalnız ekler**, geri alınabilir:

| Sütun | Ne için |
|---|---|
| `device_model` | Taramada okunan model ("YT33") |
| `vendor` | Marka ("Perkotek") |
| `adms_stamp` | ADMS'te cihazın kendi gönderim damgası |
| `discovered_at` | Kayıt taramadan mı geldi |

`devices` eşitleme kayıt defterinde **LOCAL** olduğundan bu sütunlar da düğümler arasında
taşınmaz; cihaz IP'si ve iletişim şifresi yalnız köprüyü çalıştıran düğümde kalır.

---

## 9. Cihaz olmadan kanıt

Gerçek terminal elimizde yok; bu yüzden her şey **gerçek soketlerle** sahte cihaza karşı
kanıtlanır (dış ağa çıkılmaz):

```bash
php artisan test --filter=DeviceDiscoveryTest      # süpürme + künye + süre ölçümü
php artisan test --filter=DeviceDiscoveryApiTest   # yetki, Türkçe doğrulama, tek kaynak kuralı
php artisan test --filter=AdmsPushTest             # cihazın attığı isteğin taklidi
```

`127.0.0.0/8`'in tamamı geri döngüdür: 254 adresi taramak hiçbir paketi makinenin dışına
çıkarmaz, kapalı adresler anında "reddedildi" döner. Süre ölçümü bu yüzden gerçektir.
