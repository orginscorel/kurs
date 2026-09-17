# Yoklama Köprüsü (Local Device Gateway)

Kurum binasındaki bilgisayarda çalışan, parmak izi/RFID/QR terminallerinden okuduğu
giriş-çıkış olaylarını `kurs-app` sunucusuna ileten bağımsız bir Node.js servisidir.
İnternet kesilirse olaylar yerel bir SQLite kuyruğunda birikir; bağlantı geri gelince
otomatik olarak (aynı sırayla, çift kayıt oluşturmadan) gönderilir.

Bu klasör ana uygulamadan tamamen bağımsızdır: kendi `package.json`'ı vardır,
`npm install` yalnızca bu klasörün içinde çalıştırılır.

**Gerekli Node sürümü: 22.5+.** Yerel kuyruk, Node'un yerleşik `node:sqlite`
modülünü kullanır (deneysel ama stabil) — bilinçli bir tercih: `better-sqlite3`
gibi native derleme gerektiren paketler, derleyici araçları kurulu olmayan bir
Windows bilgisayarda (ya da bu depoyu test ederken karşılaşılan eski glibc/Python
sürümlü Linux sunucularda) kurulum sırasında başarısız olabiliyor. `node:sqlite`
ile `npm install` hiçbir native modül derlemeden, saf JavaScript bağımlılıklarla
tamamlanır.

## Kurulum

```bash
cd gateway
npm install
cp config.example.json config.json
```

`config.json` içinde doldurulması gerekenler:

- `apiBaseUrl` — genelde `https://kurs.bogahostdeveloper.com.tr/api/v1` (değiştirmeyin).
- `deviceToken` — yönetim panelinde **Yoklama → Cihazlar** ekranından cihazı
  ekleyip "Jeton üret/yenile" ile alacağınız `dev_...` ile başlayan değer.
  **Bu değer yalnız bir kez gösterilir; hemen kopyalayıp buraya yapıştırın.**
- `driver` — `simulator` (test), `zkteco` (parmak izi terminali, TCP 4370) veya
  `serial-rfid` (seri port kart okuyucu).

### ZKTeco parmak izi terminali

```json
{ "driver": "zkteco", "zkteco": { "ip": "192.168.1.201", "port": 4370 } }
```

Cihazın gerçek zamanlı olay yayınının (real-time event) açık olduğundan emin olun
(çoğu cihazda varsayılan olarak açıktır). İlk kurulumda `logLevel: "debug"` yapıp
loglardaki ham paketleri kontrol edin — firmware'e göre küçük ayarlamalar
gerekebilir (bkz. `src/drivers/zkteco.js` başındaki not; bu sürücü gerçek donanıma
karşı test edilememiştir, sahada doğrulama gerekir).

### Seri port RFID okuyucu

```json
{ "driver": "serial-rfid", "serialRfid": { "path": "COM3", "baudRate": 9600 } }
```

Bu sürücü `serialport` paketini AYRICA gerektirir (çoğu kurulumda kullanılmadığı
için varsayılan bağımlılıklara eklenmedi):

```bash
npm install serialport @serialport/parser-readline
```

### Simülatör (test / QR kiosk gibi API'ye doğrudan bağlanan cihazlar)

QR kiosk zaten kendi ekranından (`/yoklama/kiosk`, yönetici oturumuyla) çalıştığı
için köprüye ihtiyaç duymaz. Simülatör sürücüsü yalnız test amaçlıdır:

```bash
npm start                         # config.json'daki simulator.autoIntervalMs > 0 ise periyodik sahte okutma üretir
npm run simulate -- identifier=1001,kind=fingerprint,type=ENTRY   # tek seferlik gerçek istek gönderir, sonucu yazdırıp çıkar
npm run simulate -- student_id=42,type=EXIT
```

`npm run simulate` sürücüyü hiç başlatmadan doğrudan kuyruğa yazıp sunucuya
gönderir — gerçek API'ye karşı uçtan uca test için idealdir.

## Çalıştırma

```bash
npm start
```

Servis şunları yapar:
1. Seçili sürücüyü başlatır (donanımdan okuma).
2. Her okutmayı yerel SQLite kuyruğuna yazar (`data/queue.sqlite3`).
3. Arka planda her birkaç saniyede bir kuyruğu toplu olarak `POST /gateway/events`
   ile sunucuya gönderir; sunucu işlediği (kabul/duplicate/eşleşmedi fark etmez)
   her olayı kuyruktan siler. Ağ hatasında üstel geri çekilme uygulanır (kayıp yok).
4. `GET /gateway/identities` ile aktif kimlik eşlemelerini periyodik çeker (yerel önbellek).
5. `POST /gateway/heartbeat` ile cihazın "çevrim içi" görünmesini sağlar.

Durdurmak için `Ctrl+C` (SIGINT) — kuyruktaki veriler kaybolmaz, bir sonraki
başlatmada kaldığı yerden devam eder.

## Windows servis olarak kurulum

```powershell
cd gateway
npm install
npm install node-windows
npm run install-windows-service
```

Kaldırmak için: `npm run uninstall-windows-service`

## Linux (systemd) örneği

```bash
sudo mkdir -p /opt/kurs-gateway
sudo cp -r gateway/* /opt/kurs-gateway/
cd /opt/kurs-gateway && npm install --omit=dev
sudo useradd -r -s /sbin/nologin kursgateway || true
sudo chown -R kursgateway:kursgateway /opt/kurs-gateway
sudo cp systemd/kurs-gateway.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now kurs-gateway
sudo journalctl -u kurs-gateway -f
```

## Dizin yapısı

```
gateway/
  config.json           kişisel ayarlar (git'e eklenmez — .gitignore'a ekleyin)
  config.example.json   örnek ayar dosyası
  data/queue.sqlite3     yerel kuyruk (otomatik oluşur)
  src/
    index.js             giriş noktası + orkestrasyon + --simulate CLI
    config.js, logger.js, db.js, sender.js, identities.js, heartbeat.js
    drivers/
      simulator.js        test sürücüsü
      zkteco.js            ZKTeco TCP 4370
      serial-rfid.js       seri port kart okuyucu (serialport paketi ayrı kurulur)
    windows-service.js    node-windows kurulum/kaldırma
  systemd/kurs-gateway.service
```

## KVKK notu

Köprü yalnızca cihazın kendi kullanıcı numarasını/kart UID'sini iletir; hiçbir
zaman ham parmak izi şablonu veya biyometrik veri sunucuya gönderilmez.
