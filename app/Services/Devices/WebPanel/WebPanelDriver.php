<?php

namespace App\Services\Devices\WebPanel;

use App\Models\Device;
use App\Services\Devices\Terminal\Data\DeviceUser;
use App\Services\Devices\Terminal\DriverResult;
use App\Services\Devices\Terminal\DriverStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PERKOTEK YT33 "DYNAMIC FACE" WEB PANEL (HTTP) SÜRÜCÜSÜ — cihaza kullanıcı yazma/okuma/silme + kayıt başlatma.
 *
 * Protokol cihazın kendi panel JS kaynağından (systemmanager.js/usermanager.js) DOĞRULANDI (2026-09-21):
 *   • Tüm komutlar: POST /bin/cmd, Content-Type application/json, gövde {"cmd":..,"data":..} (data boşsa atlanır).
 *   • Kimlik: HTTP Digest (realm "Login", MD5, qop=auth) — cihaz IP + panel kullanıcı adı + panel şifresi (şifreli).
 *   • Yanıt gövdesi bir JSON metni: {"result_code":0,"result_data":{...}}; başarı koşulu result_code == 0.
 *   • Komutlar: GetDeviceInfo · GetUserIdList{packageId} · GetUserInfo{packageId,usersId[]} ·
 *               SetUserInfo{users[]} (ekle=düzenle, update:1) · EnterEnroll{userId,feature:"fp"|"face"} ·
 *               DeleteUserInfo{usersCount,usersId[]}.
 *
 * GÜVENLİK:
 *   • DeleteUserInfo yalnız numara listesiyle çağrılır. usersCount:0 cihazda "TÜM kullanıcıları sil" demektir;
 *     boş liste ASLA cihaza gönderilmez (aşağıda katı denetim).
 *   • Biyometrik/base64 içerik (fps/face/palm/photo) uygulamaya HİÇ yazılmaz; yalnız sayı okunur (KVKK).
 *   • Yalnız köprüyü çalıştıran yerel düğümde (Mac) çağrılır; uçlar terminal.desktop ara katmanıyla korunur.
 *   • Panel şifresi/istek gövdesi günlüğe yazılmaz.
 */
class WebPanelDriver
{
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 12;

    /** Ad alanı cihazda genelde 32 baytla sınırlı; taşmayı engelle. */
    private const NAME_MAX = 32;

    public function key(): string
    {
        return 'webpanel';
    }

    /** Cihaza yazma/okuma için panel kimlik bilgileri girilmiş mi? */
    public function verified(Device $device): bool
    {
        return $device->supportsWebPanel();
    }

    // ---- Yüksek düzey işlemler --------------------------------------------------------------

    /** Cihaz künyesi (GetDeviceInfo → ham result_data). Bağlantı/kimlik testi için de kullanılır. */
    public function deviceInfo(Device $device): DriverResult
    {
        return $this->cmd($device, 'GetDeviceInfo');
    }

    /** Tek kullanıcıyı numarayla oku (GetUserInfo). Bulunmazsa data = null (durum yine Ok). */
    public function getUser(Device $device, string $no): DriverResult
    {
        $no = trim($no);
        $r = $this->cmd($device, 'GetUserInfo', ['packageId' => 0, 'usersId' => [$no]]);
        if (! $r->isOk()) {
            return $r;
        }

        $rd = is_array($r->data) ? $r->data : [];
        foreach ($this->usersFrom($rd) as $u) {
            if (is_array($u) && (string) ($u['userId'] ?? '') === $no) {
                return DriverResult::ok($this->toDeviceUser($device, $u), 'Kullanıcı cihazda bulundu.');
            }
        }

        return DriverResult::ok(null, 'Kullanıcı cihazda bulunamadı.');
    }

    /**
     * Kullanıcı ekle ya da düzenle (SetUserInfo, update:1 = varsa üzerine yaz). Biyometrik gönderilmez;
     * parmak/yüz kaydı ayrıca EnterEnroll + fiziksel okutmayla yapılır.
     *
     * @param  array{privilege?:int, card?:string, pwd?:string, vaildStart?:string, vaildEnd?:string}  $opts
     */
    public function upsertUser(Device $device, string $no, string $name, array $opts = []): DriverResult
    {
        $no = trim($no);
        if ($no === '' || ! ctype_digit($no)) {
            return DriverResult::unsupported('Cihaz kullanıcı numarası yalnız rakamlardan oluşabilir.');
        }

        $user = [
            'userId' => $no,
            'name' => $this->trimName($name),
            'privilege' => (int) ($opts['privilege'] ?? 0),   // 0 = kullanıcı, 1 = yönetici
            'photoEnroll' => 0,
            'update' => 1,
        ];
        foreach (['card', 'pwd', 'vaildStart', 'vaildEnd'] as $k) {
            if (isset($opts[$k]) && $opts[$k] !== '') {
                $user[$k] = (string) $opts[$k];
            }
        }

        return $this->cmd($device, 'SetUserInfo', ['users' => [$user]]);
    }

    /** Cihazı parmak izi (fp) / yüz (face) kayıt ekranına geçir (EnterEnroll). Kart için cihazda seçenek yok. */
    public function enterEnroll(Device $device, string $no, string $feature): DriverResult
    {
        $no = trim($no);
        $feature = strtolower(trim($feature));
        if ($no === '' || ! ctype_digit($no)) {
            return DriverResult::unsupported('Geçersiz cihaz kullanıcı numarası.');
        }
        if (! in_array($feature, ['fp', 'face'], true)) {
            return DriverResult::unsupported('Cihazda yalnız parmak izi (fp) ve yüz (face) kaydı başlatılabilir. Kart, kartı okutarak eklenir.');
        }

        return $this->cmd($device, 'EnterEnroll', ['userId' => $no, 'feature' => $feature]);
    }

    /**
     * Cihazdan kullanıcı sil (DeleteUserInfo). YALNIZ verilen numaralar silinir.
     * GÜVENLİK: boş/geçersiz liste cihaza GÖNDERİLMEZ — usersCount:0 cihazda toplu silme anlamına gelir.
     *
     * @param  list<string|int>  $nos
     */
    public function deleteUsers(Device $device, array $nos): DriverResult
    {
        $nos = array_values(array_unique(array_filter(
            array_map(fn ($n) => trim((string) $n), $nos),
            fn ($n) => $n !== '' && ctype_digit($n),
        )));

        if ($nos === []) {
            return DriverResult::unsupported('Silinecek geçerli cihaz numarası verilmedi. Güvenlik gereği cihazda toplu (tümünü) silme bu köprüden yapılmaz.');
        }

        return $this->cmd($device, 'DeleteUserInfo', ['usersCount' => count($nos), 'usersId' => $nos]);
    }

    /**
     * Cihazdaki tüm kullanıcıları oku (GetUserIdList sayfalama + GetUserInfo).
     * GetUserIdList yanıtındaki numara-listesi alan adı panel kaynağında kesinleşmediğinden savunmacı okur;
     * bulunamazsa uydurmaz, açık bir "doğrulanmadı" döner.
     *
     * @return DriverResult<list<DeviceUser>>
     */
    public function fetchUsers(Device $device, int $max = 5000): DriverResult
    {
        $ids = [];
        $packageId = 0;
        $guard = 0;

        do {
            $list = $this->cmd($device, 'GetUserIdList', ['packageId' => $packageId]);
            if (! $list->isOk()) {
                return $list;
            }
            $rd = is_array($list->data) ? $list->data : [];
            $chunk = $this->idsFrom($rd);
            if ($chunk === null) {
                return DriverResult::notImplemented(
                    'Cihaz kullanıcı listesini döndürdü ama numara alanı panel kaynağında kesinleşmedi.',
                    'Kayıt doğrulama tek numarayla (GetUserInfo) yapılır; toplu liste için panel yanıt biçimi doğrulanmalı.',
                );
            }
            foreach ($chunk as $id) {
                $ids[$id] = true;
            }
            $packageId = (int) ($rd['packageId'] ?? 0);
            $guard++;
        } while ($packageId !== 0 && $guard < 200 && count($ids) < $max);

        $ids = array_keys($ids);
        $users = [];
        foreach (array_chunk($ids, 50) as $part) {
            $info = $this->cmd($device, 'GetUserInfo', ['packageId' => 0, 'usersId' => array_values($part)]);
            if (! $info->isOk()) {
                return $info;
            }
            foreach ($this->usersFrom(is_array($info->data) ? $info->data : []) as $u) {
                if (is_array($u)) {
                    $users[] = $this->toDeviceUser($device, $u);
                }
            }
        }

        return DriverResult::ok($users, count($users).' kullanıcı okundu.');
    }

    /** Panelde okutma/kayıt logu çeken bir komut yok (kaynakta bulunamadı); okutmalar push ile gelir. */
    public function fetchAttendanceLogs(Device $device, ?CarbonImmutable $since = null): DriverResult
    {
        return DriverResult::unsupported(
            'Dynamic Face web panelinde okutma kaydı çeken bir komut yok.',
            'Okutmalar cihaz push (Terminal Köprüsü › Push) ile gelir; web paneli yalnız kullanıcı yazma/okuma içindir.',
        );
    }

    // ---- /bin/cmd düşük düzey çağrı ---------------------------------------------------------

    /**
     * Tek /bin/cmd çağrısı. Başarıda DriverResult::ok(result_data); aksi hâlde tiplenmiş Türkçe hata.
     */
    public function cmd(Device $device, string $cmd, ?array $data = null): DriverResult
    {
        if (! $device->supportsWebPanel()) {
            return DriverResult::notImplemented(
                'Cihazın web paneli için kullanıcı adı ve şifre girilmemiş.',
                'Yoklama › Cihazlar › Web paneli ayarından cihaz IP, panel kullanıcı adı ve şifresini kaydedin.',
            );
        }

        $payload = ($data === null || $data === []) ? ['cmd' => $cmd] : ['cmd' => $cmd, 'data' => $data];

        try {
            $resp = Http::withDigestAuth((string) $device->panel_user, (string) $device->panel_password)
                ->withOptions(['verify' => false])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->withBody((string) json_encode($payload, JSON_UNESCAPED_UNICODE), 'application/json')
                ->post($this->base($device).'/bin/cmd');
        } catch (ConnectionException $e) {
            $this->log($device, $cmd, 'ulasilamadi');

            return new DriverResult(
                DriverStatus::NetworkError,
                "Cihaza ulaşılamadı ({$device->zk_ip}). Cihaz açık ve aynı yerel ağda mı?",
                'Bu işlem yalnız cihazın bulunduğu ağdaki Mac uygulamasından yapılabilir; IP ve panel portunu kontrol edin.',
            );
        } catch (Throwable $e) {
            $this->log($device, $cmd, 'hata');

            return new DriverResult(DriverStatus::NetworkError, 'Cihazla iletişimde hata oluştu.', 'Bağlantıyı ve panel ayarını kontrol edip tekrar deneyin.');
        }

        if ($resp->status() === 401) {
            $this->log($device, $cmd, '401');

            return new DriverResult(DriverStatus::AuthError, 'Panel kullanıcı adı ya da şifresi yanlış (cihaz 401 döndü).', 'Web paneli ayarından kullanıcı adı ve şifreyi güncelleyin.');
        }
        if (! $resp->successful()) {
            $this->log($device, $cmd, 'http'.$resp->status());

            return new DriverResult(DriverStatus::ProtocolError, "Cihaz beklenmeyen bir yanıt verdi (HTTP {$resp->status()}).", 'Panel adresini ve portu kontrol edin.');
        }

        $json = json_decode($resp->body(), true);
        if (! is_array($json) || ! array_key_exists('result_code', $json)) {
            $this->log($device, $cmd, 'cozulmedi');

            return new DriverResult(DriverStatus::ProtocolError, 'Cihaz yanıtı çözülemedi.', 'Beklenen biçim {"result_code":...} değildi.');
        }

        $code = (int) $json['result_code'];
        $this->log($device, $cmd, 'result_code='.$code);

        if ($code !== 0) {
            return new DriverResult(DriverStatus::ProtocolError, self::errorMessage($cmd, $code), 'Cihaz hata kodu: '.$code, null, ['result_code' => $code]);
        }

        return DriverResult::ok($json['result_data'] ?? [], 'Cihaz komutu başarılı.');
    }

    // ---- Yardımcılar ------------------------------------------------------------------------

    private function base(Device $device): string
    {
        $ip = trim((string) $device->zk_ip);
        $port = (int) ($device->panel_port ?: 80);

        return 'http://'.$ip.($port === 80 ? '' : ':'.$port);
    }

    private function trimName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return mb_strlen($name) > self::NAME_MAX ? rtrim(mb_substr($name, 0, self::NAME_MAX)) : $name;
    }

    /** GetUserInfo yanıtındaki kullanıcı dizisi (result_data.users ya da düz result_data dizisi). */
    private function usersFrom(array $rd): array
    {
        if (isset($rd['users']) && is_array($rd['users'])) {
            return $rd['users'];
        }

        return array_is_list($rd) ? $rd : [];
    }

    /**
     * GetUserIdList yanıtından numara listesi. Alan adı kaynakta kesinleşmediğinden birkaç makul ada bakar;
     * hiçbiri yoksa null döner (çağıran "doğrulanmadı" der, uydurmaz).
     *
     * @return list<string>|null
     */
    private function idsFrom(array $rd): ?array
    {
        foreach (['usersId', 'userIds', 'users', 'userList', 'idList'] as $field) {
            if (isset($rd[$field]) && is_array($rd[$field])) {
                return array_values(array_filter(array_map(
                    fn ($u) => is_array($u) ? (string) ($u['userId'] ?? '') : (string) $u,
                    $rd[$field],
                ), fn ($v) => $v !== ''));
            }
        }

        return null;
    }

    private function toDeviceUser(Device $device, array $u): DeviceUser
    {
        $count = static function ($v): ?int {
            if (is_array($v)) {
                return count(array_filter($v, fn ($x) => $x !== null && $x !== '' && $x !== '0'));
            }

            return null;
        };
        $card = trim((string) ($u['card'] ?? ''));
        $pwd = (string) ($u['pwd'] ?? '');

        return new DeviceUser(
            deviceId: (int) $device->id,
            deviceUserId: (string) ($u['userId'] ?? ''),
            name: (string) ($u['name'] ?? ''),
            cardNo: $card !== '' && $card !== '0' ? $card : null,
            fingerprintCount: array_key_exists('fps', $u) ? $count($u['fps']) : null,
            faceCount: array_key_exists('face', $u) ? ($count($u['face']) ?? (empty($u['face']) ? 0 : 1)) : null,
            palmCount: array_key_exists('palm', $u) ? $count($u['palm']) : null,
            passwordExists: $pwd !== '' && $pwd !== '0',
            rawData: [],   // KVKK: biyometrik/base64 (fps/face/palm/photo) SAKLANMAZ
        );
    }

    /** Kaynakta cihazın sayısal hata kodu eşlemesi yok; komuta göre panelin genel mesajını Türkçeye çevir. */
    private static function errorMessage(string $cmd, int $code): string
    {
        return match ($cmd) {
            'SetUserInfo' => 'Cihaz kullanıcıyı kaydedemedi (personel değişikliği başarısız).',
            'DeleteUserInfo' => 'Cihaz kullanıcıyı silemedi.',
            'GetUserInfo' => 'Cihazdan kullanıcı bilgisi alınamadı.',
            'GetUserIdList' => 'Cihazdan kullanıcı listesi alınamadı.',
            'EnterEnroll' => 'Cihaz kayıt ekranına geçemedi (komut iletilemedi).',
            default => "Cihaz komutu başarısız oldu (kod {$code}).",
        };
    }

    private function log(Device $device, string $cmd, string $result): void
    {
        // Yalnız komut adı + sonuç; istek gövdesi ve panel şifresi ASLA yazılmaz.
        Log::channel('terminal')->info('Web paneli komutu', ['cihaz' => $device->id, 'ip' => $device->zk_ip, 'cmd' => $cmd, 'sonuc' => $result]);
    }
}
