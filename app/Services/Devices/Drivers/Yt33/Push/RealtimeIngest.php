<?php

namespace App\Services\Devices\Drivers\Yt33\Push;

use App\Models\Device;
use App\Models\DeviceIdentity;
use App\Models\Employee;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Attendance\PdksService;
use App\Services\Attendance\PresenceService;
use App\Services\Attendance\TerminalEnrollmentService;
use App\Services\Devices\Terminal\Data\AttendanceEvent;
use App\Services\Devices\Terminal\Data\Direction;
use App\Services\Devices\Terminal\Data\VerificationMethod;
use App\Services\Devices\Terminal\TerminalPacketStore;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * YT33 push isteklerini uygulamaya işler:
 *   • realtime_glog         → okutma: PresenceService::ingest (öğrenci yoklaması / personel PDKS / eşleşmeyen bekler)
 *   • realtime_enroll_data  → cihaz kullanıcısı: terminal_device_users'a yazılır; adı şubede TEK bir kişiyle birebir
 *                             eşleşirse PDKS eşlemesi (device_identities) otomatik kurulur. Şablon hiç saklanmaz.
 * Ham paket her durumda terminal_raw_packets'te kalır; sonuç parse_status/note sütunlarına yazılır
 * (parsed | unmatched_dev | failed) → cihaz sonradan tanımlanırsa `kurs:terminal-isle` yeniden işler.
 */
class RealtimeIngest
{
    public const PREFIX = 'fk';

    public int $events = 0;

    public ?string $lastEventAt = null;

    public function __construct(
        private readonly PresenceService $presence,
        private readonly PdksService $pdks,
        private readonly TerminalEnrollmentService $enrollment,
    ) {}

    /** @return array{status:string, note:string} */
    public function handle(array $parsed, string $remoteIp, ?int $packetId = null): array
    {
        $result = $this->process($parsed, $remoteIp);

        if ($packetId) {
            $redacted = str_starts_with((string) DB::table(TerminalPacketStore::TABLE)->where('id', $packetId)->value('note'), 'Biyometrik');
            DB::table(TerminalPacketStore::TABLE)->where('id', $packetId)->update([
                'parse_status' => $result['status'],
                'note' => mb_substr($result['note'].($redacted ? ' Şablon gizlendi (KVKK).' : ''), 0, 190),
                'device_id' => $result['device_id'] ?? DB::raw('device_id'),
                'updated_at' => now(),
            ]);
        }

        Log::channel('terminal')->info('YT33 push işlendi', ['paket' => $packetId, 'kod' => $parsed['request_code'], 'durum' => $result['status'], 'not' => $result['note']]);

        return ['status' => $result['status'], 'note' => $result['note']];
    }

    /**
     * Saklanmış ama işlenmemiş push isteklerini (dinleyici eskiyken ya da cihaz tanımsızken gelenler) sırayla işler.
     * Okutmalar idempotency anahtarıyla korunur → tekrar işlemek çift kayıt üretmez.
     *
     * @return array{taranan:int, islenen:int, cihazsiz:int, hatali:int}
     */
    public function reprocess(int $limit = 5000, bool $retryFailed = false): array
    {
        $stats = ['taranan' => 0, 'islenen' => 0, 'cihazsiz' => 0, 'hatali' => 0];
        $statuses = $retryFailed ? ['unparsed', 'unmatched_dev', 'failed'] : ['unparsed', 'unmatched_dev'];

        DB::table(TerminalPacketStore::TABLE)->where('source', 'push')->where('format', 'http')
            ->where('direction', '!=', 'upstream_to_device')->whereIn('parse_status', $statuses)
            ->orderBy('id')->limit($limit)->get(['id', 'remote_ip', 'payload_base64'])
            ->each(function ($row) use (&$stats) {
                $stats['taranan']++;
                $parsed = RealtimeProtocol::parse((string) base64_decode($row->payload_base64));
                if ($parsed === null) {
                    DB::table(TerminalPacketStore::TABLE)->where('id', $row->id)->update(['parse_status' => 'not_fk', 'updated_at' => now()]);

                    return;
                }
                $r = $this->handle($parsed, (string) $row->remote_ip, (int) $row->id);
                match ($r['status']) {
                    'parsed' => $stats['islenen']++,
                    'unmatched_dev' => $stats['cihazsiz']++,
                    default => $stats['hatali']++,
                };
            });

        return $stats;
    }

    private function process(array $parsed, string $remoteIp): array
    {
        $code = $parsed['request_code'];

        if (! in_array($code, [RealtimeProtocol::GLOG, RealtimeProtocol::ENROLL], true)) {
            return ['status' => 'parsed', 'note' => "FK isteği \"{$code}\" — onaylandı, işlenecek veri yok."];
        }

        if (! is_array($parsed['data'])) {
            return ['status' => 'failed', 'note' => 'JSON gövdesi çözülemedi: '.($parsed['json_error'] ?? 'boş gövde')];
        }

        $device = $this->device($remoteIp, $parsed['dev_id']);

        if (! $device) {
            return ['status' => 'unmatched_dev', 'note' => "Cihaz tanımlı değil ({$remoteIp}, dev_id {$parsed['dev_id']}). Yoklama › Cihazlar'da IP'yi girin; paket sonra işlenir."];
        }

        try {
            $out = $code === RealtimeProtocol::GLOG
                ? $this->glog($device, $parsed)
                : $this->enroll($device, $parsed['data']);
        } catch (\Throwable $e) {
            report($e);

            return ['status' => 'failed', 'note' => 'İşlenemedi: '.$e->getMessage(), 'device_id' => $device->id];
        }

        $device->forceFill(['last_seen_at' => now(), 'last_ip' => $remoteIp])->saveQuietly();

        return $out + ['device_id' => $device->id];
    }

    private function glog(Device $device, array $parsed): array
    {
        $d = $parsed['data'];
        $user = trim((string) ($d['userId'] ?? $d['user_id'] ?? ''));
        $at = RealtimeProtocol::time($d['time'] ?? null);

        if ($user === '' || ! $at) {
            return ['status' => 'failed', 'note' => 'Okutmada kullanıcı no ya da geçerli saat yok.'];
        }

        $event = new AttendanceEvent(
            deviceId: $device->id,
            deviceUserId: $user,
            occurredAt: $at,
            verificationMethod: self::verifyMethod((string) ($d['verifyMode'] ?? '')),
            direction: Direction::Unknown,   // ioMode anlamı doğrulanmadı → giriş/çıkış sıradan (ya da cihaz yönünden)
            rawPayload: $d,
            receivedAt: now()->toImmutable(),
        );

        $result = $this->presence->ingest($event->toIngestPayload(self::PREFIX), $device);
        $this->events++;
        $this->lastEventAt = now()->toIso8601String();

        $label = match ($result['status']) {
            'accepted' => 'öğrenci yoklamasına işlendi',
            'staff' => 'personel PDKS\'ye işlendi',
            'duplicate' => 'zaten işlenmişti (tekrar gönderim)',
            'unmatched' => 'kişi eşlemesi yok — PDKS\'de bekliyor',
            default => $result['status'],
        };

        return ['status' => 'parsed', 'note' => "Okutma {$user} · ".$at->format('d.m.Y H:i:s')." · {$label}."];
    }

    private function enroll(Device $device, array $d): array
    {
        $user = trim((string) ($d['userId'] ?? $d['user_id'] ?? ''));

        if ($user === '') {
            return ['status' => 'failed', 'note' => 'Kayıt verisinde kullanıcı no yok.'];
        }

        $name = trim(preg_replace('/\s+/u', ' ', (string) ($d['name'] ?? '')) ?? '');
        $fps = $d['fps'] ?? null;
        $face = $d['face'] ?? $d['faces'] ?? $d['face_data'] ?? $d['facedata'] ?? null;
        $now = now();

        DB::table('terminal_device_users')->updateOrInsert(
            ['device_id' => $device->id, 'user_no' => $user],
            [
                'branch_id' => $device->branch_id,
                'name' => mb_substr($name, 0, 120) ?: null,
                'card_no' => mb_substr(trim((string) ($d['card'] ?? '')), 0, 40) ?: null,
                'privilege' => is_numeric($d['privilege'] ?? null) ? (int) $d['privilege'] : null,
                // Şablon sayısı gizlemeden önce okunur; şablonun kendisi saklanmaz
                'fingerprint_count' => is_array($fps) ? count(array_filter($fps, fn ($f) => $f !== null && $f !== '')) : null,
                'face_count' => is_array($face) ? count(array_filter($face)) : ($face !== null && $face !== '' ? 1 : 0),
                'valid_from' => mb_substr((string) ($d['vaildStart'] ?? $d['validStart'] ?? ''), 0, 20) ?: null,
                'valid_until' => mb_substr((string) ($d['vaildEnd'] ?? $d['validEnd'] ?? ''), 0, 20) ?: null,
                'last_enrolled_at' => $now,
                'updated_at' => $now,
            ],
        );

        // Açık "Terminale kaydet" oturumu varsa kayıt o kişiye bağlanır (ada bakılmaz); yoksa adla tekil eşleme denenir
        $wizard = app(BranchContext::class)->run((int) $device->branch_id, fn () => $this->enrollment->onEnroll($device, $user));
        $link = $wizard !== null ? ['status' => 'linked', 'note' => $wizard] : $this->autoLink($device, $user, $name);

        DB::table('terminal_device_users')->where('device_id', $device->id)->where('user_no', $user)
            ->update(['link_status' => $link['status'], 'created_at' => DB::raw('COALESCE(created_at, updated_at)')]);

        return ['status' => 'parsed', 'note' => "Cihaz kullanıcısı {$user}".($name !== '' ? " ({$name})" : '')." · {$link['note']}"];
    }

    /**
     * Otomatik PDKS eşlemesi — yalnız kesin durumda: numara henüz kimseye bağlı değil VE ad şubedeki etkin
     * kişiler arasında birebir (Türkçe harf/boşluk farkı gözetmeden) TEK kişiye uyuyor. Aksi hâlde elle eşleme bekler.
     *
     * @return array{status:string, note:string}
     */
    private function autoLink(Device $device, string $user, string $name): array
    {
        $existing = DeviceIdentity::query()->withoutGlobalScope('branch')
            ->where('branch_id', $device->branch_id)->where('kind', 'fingerprint')->where('identifier', $user)->exists();

        if ($existing) {
            return ['status' => 'existing', 'note' => 'PDKS eşlemesi zaten var.'];
        }

        $key = self::nameKey($name);

        if (mb_strlen($key) < 3) {
            return ['status' => 'no_name', 'note' => 'cihazda ad yok — PDKS\'de elle eşleyin.'];
        }

        $candidates = $this->candidates((int) $device->branch_id, $key);

        if (count($candidates) !== 1) {
            return count($candidates) === 0
                ? ['status' => 'no_match', 'note' => 'bu adla kişi bulunamadı — PDKS\'de elle eşleyin.']
                : ['status' => 'ambiguous', 'note' => count($candidates).' kişi aynı adı taşıyor — PDKS\'de elle eşleyin.'];
        }

        [$type, $id] = $candidates[0];
        $done = app(BranchContext::class)->run((int) $device->branch_id,
            fn () => $this->pdks->link((int) $device->branch_id, $user, $type, $id, false, 'fingerprint', true));

        return ['status' => 'linked', 'note' => "PDKS'de otomatik eşlendi: {$done['kisi']} (".PdksService::PERSON_TYPES[$type].')'
            .($done['baglanan_eski_okutma'] ? ", {$done['baglanan_eski_okutma']} eski okutma bağlandı." : '.')];
    }

    /** @return list<array{0:string,1:int}> */
    private function candidates(int $branchId, string $key): array
    {
        $out = [];

        Student::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)
            ->whereNotIn('status', ['withdrawn', 'graduated'])->select(['id', 'full_name'])
            ->chunkById(500, function ($rows) use (&$out, $key) {
                foreach ($rows as $s) {
                    if (self::nameKey((string) $s->full_name) === $key) {
                        $out[] = ['student', (int) $s->id];
                    }
                }
            });

        foreach (['teacher' => Teacher::class, 'employee' => Employee::class] as $type => $model) {
            foreach ($model::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)->where('is_active', true)
                ->get(['id', 'first_name', 'last_name']) as $p) {
                if (self::nameKey($p->first_name.' '.$p->last_name) === $key) {
                    $out[] = [$type, (int) $p->id];
                }
            }
        }

        return $out;
    }

    /** "Şükrü  Işık" = "SUKRU ISIK" = "sükrü ışık" (cihaz menüleri çoğu zaman Türkçe harf yazamaz). */
    public static function nameKey(string $name): string
    {
        $name = strtr($name, ['ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g', 'ı' => 'i', 'I' => 'i', 'İ' => 'i', 'ö' => 'o', 'Ö' => 'o', 'ş' => 's', 'Ş' => 's', 'ü' => 'u', 'Ü' => 'u', 'â' => 'a', 'Â' => 'a', 'î' => 'i', 'û' => 'u']);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($name)) ?? '');
    }

    public static function verifyMethod(string $mode): VerificationMethod
    {
        $m = strtolower($mode);

        return match (true) {
            str_contains($m, 'fp') || str_contains($m, 'finger') => VerificationMethod::Fingerprint,
            str_contains($m, 'face') => VerificationMethod::Face,
            str_contains($m, 'card') || str_contains($m, 'rf') => VerificationMethod::Card,
            str_contains($m, 'palm') => VerificationMethod::Palm,
            str_contains($m, 'pw') || str_contains($m, 'pass') => VerificationMethod::Password,
            default => VerificationMethod::Unknown,
        };
    }

    /** Kaynak IP (devices.zk_ip) → cihaz seri no (dev_id) → şubedeki TEK Perkotek FK cihazı. */
    private function device(string $ip, string $devId): ?Device
    {
        $q = fn () => Device::query()->withoutGlobalScope('branch')->where('is_active', true);

        return $q()->where('zk_ip', $ip)->orderBy('id')->first()
            ?? ($devId !== '' ? $q()->where('serial_no', $devId)->orderBy('id')->first() : null)
            ?? (($fk = $q()->where('protocol', 'perkotek_fk')->limit(2)->get())->count() === 1 ? $fk->first() : null);
    }
}
