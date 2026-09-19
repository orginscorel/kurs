<?php

namespace App\Services\Devices\Drivers;

use App\Services\Devices\Terminal\Data\AttendanceEvent;
use App\Services\Devices\Terminal\Data\DeviceUser;
use App\Services\Devices\Terminal\Data\Direction;
use App\Services\Devices\Terminal\Data\VerificationMethod;
use App\Services\Devices\Terminal\DriverResult;
use App\Services\Devices\Terminal\DriverStatus;
use App\Services\Devices\Terminal\TerminalEndpoint;
use App\Services\Devices\Zk\Exceptions\ZkAuthException;
use App\Services\Devices\Zk\Exceptions\ZkConnectionException;
use App\Services\Devices\Zk\Exceptions\ZkException;
use App\Services\Devices\Zk\ZkAttendanceRecord;
use App\Services\Devices\Zk\ZkConnectionSettings;
use App\Services\Devices\Zk\ZkTerminal;
use App\Services\Devices\Zk\ZkUser;
use Carbon\CarbonImmutable;

/**
 * ZKTeco "PC bağlantısı" protokolü (UDP/TCP, fabrika portu 4370) — var olan App\Services\Devices\Zk
 * katmanının TerminalDriver arayüzü arkasındaki yüzü. ZKTeco (ve ZK protokolü konuşan OEM) cihazlar içindir.
 *
 * DİKKAT: Her Perkotek cihazı ZK protokolü konuşmaz. Perkotek YT33 / "Dynamic Face" (FK ailesi) farklı bir
 * protokol kullanır → Yt33\Yt33Driver. Bu sürücü ZK paketlerini yalnız ZK seçilmiş cihaza gönderir.
 */
class ZkTecoDriver extends AbstractTerminalDriver
{
    /** ZKTeco'nun kendi fabrika portu — yalnız bu sürücünün varsayılanı; genel bir varsayım değildir. */
    public const DEFAULT_PORT = 4370;

    public function key(): string
    {
        return 'zk';
    }

    public function label(): string
    {
        return 'ZKTeco protokolü (4370)';
    }

    public function vendors(): array
    {
        return ['ZKTeco', 'ZK protokolü konuşan OEM terminaller'];
    }

    public function capabilities(): array
    {
        return ['tarama' => true, 'cekme' => true, 'itme' => false, 'kullanicilar' => true];
    }

    public function defaultPort(): ?int
    {
        return (int) config('devices_zk.port', self::DEFAULT_PORT);
    }

    public function transports(): array
    {
        return ['tcp', 'udp'];
    }

    public function protocolVerified(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            ['ad' => 'ip', 'etiket' => 'IP adresi', 'tur' => 'ip', 'zorunlu' => true, 'ipucu' => 'Cihaz menüsü: Comm > Ethernet. Sabit IP verin, DHCP kapalı olsun.'],
            ['ad' => 'port', 'etiket' => 'Port', 'tur' => 'sayi', 'varsayilan' => $this->defaultPort(), 'ipucu' => 'ZKTeco fabrika değeri 4370.'],
            ['ad' => 'transport', 'etiket' => 'Bağlantı türü', 'tur' => 'secim', 'varsayilan' => 'tcp',
                'secenekler' => [['deger' => 'tcp', 'etiket' => 'TCP (önerilen)'], ['deger' => 'udp', 'etiket' => 'UDP (eski aygıt yazılımları)']]],
            ['ad' => 'comm_key', 'etiket' => 'İletişim şifresi', 'tur' => 'gizli', 'ipucu' => 'Cihaz menüsü: Comm > İletişim Şifresi (yalnız rakam). Cihazın web arayüzü şifresi DEĞİLDİR.'],
            $this->directionField(),
        ];
    }

    public function setupSteps(): array
    {
        return [
            'Cihaz menüsü: Comm > Ethernet → sabit IP verin (ör. 192.168.1.50), DHCP kapalı.',
            'ZKTeco cihazlarda Comm > PC Bağlantısı → port (fabrika değeri 4370).',
            'Comm > İletişim Şifresi → 0 (kapalı) ya da hatırlayacağınız bir sayı.',
            'Sistem > Tarih/Saat → bilgisayarla aynı saat.',
            'Cihaz ve bilgisayar AYNI wifi/ağda olmalı (misafir ağı olmaz).',
        ];
    }

    public function connect(TerminalEndpoint $endpoint): DriverResult
    {
        return $this->session($endpoint, fn () => DriverResult::ok(null, 'ZKTeco el sıkışması başarılı.'));
    }

    public function identifyDevice(TerminalEndpoint $endpoint): DriverResult
    {
        return $this->session($endpoint, fn (ZkTerminal $t) => DriverResult::ok($t->info()->toArray(), 'Cihaz künyesi okundu.'));
    }

    public function fetchUsers(TerminalEndpoint $endpoint, int $deviceId): DriverResult
    {
        return $this->session($endpoint, fn (ZkTerminal $t) => DriverResult::ok(
            array_map(fn (ZkUser $u) => $this->normalizeUser($u, $deviceId), $t->users()),
            'Cihaz kullanıcıları okundu.',
        ));
    }

    public function fetchAttendanceLogs(TerminalEndpoint $endpoint, int $deviceId, ?CarbonImmutable $since = null): DriverResult
    {
        return $this->session($endpoint, fn (ZkTerminal $t) => DriverResult::ok(
            array_map(fn (ZkAttendanceRecord $r) => $this->normalizeEvent($r, $deviceId), $t->attendance($since, (int) config('devices_zk.max_records', 20000))),
            'Yoklama kayıtları okundu.',
        ));
    }

    public function normalizeUser(ZkUser $user, int $deviceId): DeviceUser
    {
        return new DeviceUser(
            deviceId: $deviceId,
            deviceUserId: $user->userId,
            name: $user->name,
            cardNo: $user->card !== '' && $user->card !== '0' ? $user->card : null,
            passwordExists: $user->hasPassword,
            rawData: ['uid' => $user->uid, 'yetki' => $user->privilege, 'grup' => $user->group],
        );
    }

    public function normalizeEvent(ZkAttendanceRecord $record, int $deviceId): AttendanceEvent
    {
        $source = $record->source((array) config('devices_zk.verify_sources', []));

        return new AttendanceEvent(
            deviceId: $deviceId,
            deviceUserId: $record->userId,
            occurredAt: $record->timestamp,
            verificationMethod: match ($source) {
                'fingerprint' => VerificationMethod::Fingerprint,
                'face' => VerificationMethod::Face,
                'rfid' => VerificationMethod::Card,
                'password' => VerificationMethod::Password,
                default => VerificationMethod::Unknown,
            },
            direction: Direction::fromEventType($record->direction((array) config('devices_zk.punch_directions', []))),
            rawPayload: $record->toArray(),
            receivedAt: CarbonImmutable::now(),
        );
    }

    /** Bağlan → iş → MUTLAKA kapat; ZK istisnalarını tiplenmiş sonuca çevir. */
    private function session(TerminalEndpoint $endpoint, callable $work): DriverResult
    {
        $terminal = null;

        try {
            $terminal = ZkTerminal::open(ZkConnectionSettings::fromArray([
                'host' => $endpoint->host,
                'port' => $endpoint->port,
                'transport' => $endpoint->transport,
                'comm_key' => $endpoint->commKey(),
                'connect_timeout' => $endpoint->connectTimeout,
                'read_timeout' => $endpoint->readTimeout,
            ]));

            return $work($terminal);
        } catch (ZkException $e) {
            $status = match (true) {
                $e instanceof ZkConnectionException && ($e->context['hedef'] ?? null) !== null && ! isset($e->context['beklenen']) => DriverStatus::NetworkError,
                $e instanceof ZkAuthException => DriverStatus::AuthError,
                default => DriverStatus::ProtocolError,
            };

            if ($status === DriverStatus::ProtocolError) {
                // Soket açıktı ama ZK el sıkışması/okuması yanıtsız kaldı: en olası neden yanlış sürücü.
                return new DriverResult($status, 'Cihaz ZKTeco protokolüne yanıt vermedi ('.$e->getMessage().')',
                    'Bu cihaz ZKTeco protokolü konuşmuyor olabilir. Perkotek YT33 / FK "Dynamic Face" ise Sürücü alanından "Perkotek YT33 / FK Dynamic Face" seçin (ZKTeco komutları o cihaza gönderilmez). '
                    .'Gerçekten ZKTeco cihazsa: cihazın kendi programı açıksa kapatın, portun doğru olduğunu (ZKTeco fabrika değeri 4370) kontrol edip tekrar deneyin.',
                    null, ['kod' => $e->code()] + $e->context);
            }

            return new DriverResult($status, $e->getMessage(), $e->hint, null, ['kod' => $e->code()] + $e->context);
        } finally {
            $terminal?->close();
        }
    }
}
