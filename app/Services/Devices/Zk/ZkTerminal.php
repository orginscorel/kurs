<?php

namespace App\Services\Devices\Zk;

use App\Services\Devices\Zk\Exceptions\ZkException;
use Carbon\CarbonImmutable;

/**
 * YÜKSEK SEVİYE ARAYÜZ — uygulamanın gördüğü tek yüz.
 *
 * Bağlan → künye / saat / kullanıcılar / yoklama kayıtları → kapat.
 * Büyük okumalarda cihaz geçici olarak kilitlenir (CMD_DISABLEDEVICE) ve hata olsa bile
 * MUTLAKA yeniden açılır (try/finally) — yoksa terminal ekranında kilitli kalır.
 */
class ZkTerminal
{
    private ?array $sizes = null;

    /** @var list<ZkUser>|null */
    private ?array $userCache = null;

    public function __construct(private readonly ZkClient $client) {}

    public static function open(ZkConnectionSettings $settings): self
    {
        return new self(ZkClient::open($settings));
    }

    public function client(): ZkClient
    {
        return $this->client;
    }

    /** Cihaz künyesi: seri no, model, yazılım, sayaçlar, cihaz saati. */
    public function info(): ZkDeviceInfo
    {
        $sizes = $this->sizes();

        return new ZkDeviceInfo(
            serialNumber: $this->parameter('~SerialNumber'),
            deviceName: $this->parameter('~DeviceName'),
            platform: $this->parameter('~Platform'),
            firmware: $this->firmware(),
            fingerAlgorithm: $this->parameter('~ZKFPVersion'),
            macAddress: $this->parameter('MAC'),
            userCount: $sizes['users'],
            fingerCount: $sizes['fingers'],
            recordCount: $sizes['records'],
            userCapacity: $sizes['users_cap'],
            recordCapacity: $sizes['records_cap'],
            deviceTime: $this->timeSafe()?->format('Y-m-d H:i:s'),
        );
    }

    /** Cihazdaki kullanıcılar (ad + kart + yetki). Parmak izi ŞABLONU OKUNMAZ. */
    public function users(): array
    {
        if ($this->userCache !== null) {
            return $this->userCache;
        }

        $count = $this->sizes()['users'] ?? 0;
        if ($count <= 0) {
            return $this->userCache = [];
        }

        $data = $this->guarded(fn () => $this->client->readData(ZkProtocol::CMD_USERTEMP_RRQ, ZkProtocol::FCT_USER));

        return $this->userCache = ZkCodec::users($data, $count);
    }

    /**
     * Yoklama (attlog) kayıtları.
     *
     * @param  CarbonImmutable|null  $since  verilirse yalnız bu andan SONRAKİ kayıtlar döner (imleç)
     * @param  int  $max  en çok kaç kayıt döndürülür (en yeniler)
     * @return list<ZkAttendanceRecord>
     */
    public function attendance(?CarbonImmutable $since = null, int $max = 20000): array
    {
        $count = $this->sizes()['records'] ?? 0;
        if ($count <= 0) {
            return [];
        }

        $data = $this->guarded(fn () => $this->client->readData(ZkProtocol::CMD_ATTLOG_RRQ, 0));

        // 8 baytlık eski biçimde kayıtta kullanıcı numarası yoktur; uid → numara eşlemesi gerekir.
        $byUid = [];
        if (strlen($data) >= 4 && $count > 0 && intdiv(unpack('V', substr($data, 0, 4))[1], $count) === 8) {
            foreach ($this->users() as $user) {
                $byUid[$user->uid] = $user->userId;
            }
        }

        $records = ZkCodec::attendance($data, $count, $byUid);

        if ($since !== null) {
            $records = array_values(array_filter($records, fn (ZkAttendanceRecord $r) => $r->timestamp->greaterThanOrEqualTo($since)));
        }

        usort($records, fn (ZkAttendanceRecord $a, ZkAttendanceRecord $b) => $a->timestamp <=> $b->timestamp);

        return $max > 0 && count($records) > $max ? array_slice($records, -$max) : $records;
    }

    public function time(): CarbonImmutable
    {
        $packet = $this->client->command(ZkProtocol::CMD_GET_TIME, '', 'saat okuma');

        return ZkProtocol::decodeTimeBytes($packet->data);
    }

    /** Cihaz saatini yazar. Ardından CMD_REFRESHDATA gönderilir (yoksa değişiklik etkin olmaz). */
    public function setTime(CarbonImmutable $time): void
    {
        $this->client->command(ZkProtocol::CMD_SET_TIME, pack('V', ZkProtocol::encodeTime($time)), 'saat yazma');
        $this->client->send(ZkProtocol::CMD_REFRESHDATA);
    }

    /** Sayaçlar: kullanıcı / parmak / kayıt sayısı ve kapasiteler. */
    public function sizes(): array
    {
        if ($this->sizes !== null) {
            return $this->sizes;
        }

        try {
            $packet = $this->client->command(ZkProtocol::CMD_GET_FREE_SIZES, '', 'sayaç okuma');

            return $this->sizes = ZkCodec::sizes($packet->data);
        } catch (ZkException) {
            return $this->sizes = ['users' => null, 'fingers' => null, 'records' => null, 'users_cap' => null, 'records_cap' => null];
        }
    }

    public function userCountSafe(): ?int
    {
        return $this->sizes()['users'] ?? null;
    }

    public function close(): void
    {
        $this->client->close();
    }

    /** Parametre okuma; cihaz desteklemiyorsa null döner (bağlantıyı bozmaz). */
    private function parameter(string $name): ?string
    {
        try {
            $packet = $this->client->send(ZkProtocol::CMD_OPTIONS_RRQ, $name."\0");

            if (! $packet->isSuccess()) {
                return null;
            }

            $value = ZkProtocol::parseParameter($packet->data);

            return $value === '' ? null : $value;
        } catch (ZkException) {
            return null;
        }
    }

    private function firmware(): ?string
    {
        try {
            $packet = $this->client->send(ZkProtocol::CMD_GET_VERSION);

            return $packet->isSuccess() ? (ZkCodec::cstring($packet->data) ?: null) : null;
        } catch (ZkException) {
            return null;
        }
    }

    private function timeSafe(): ?CarbonImmutable
    {
        try {
            return $this->time();
        } catch (ZkException) {
            return null;
        }
    }

    /** Büyük okuma sırasında cihazı kilitler; ne olursa olsun geri açar. */
    private function guarded(callable $work): string
    {
        $lock = (bool) config('devices_zk.disable_during_read', true);

        if ($lock) {
            try {
                $this->client->disableDevice();
            } catch (ZkException) {
                $lock = false;   // cihaz kilitlemeyi desteklemiyor; okumaya yine de devam
            }
        }

        try {
            return $work();
        } finally {
            if ($lock) {
                try {
                    $this->client->enableDevice();
                } catch (ZkException) {
                    // kapanışta hata yutulur
                }
            }
        }
    }
}
