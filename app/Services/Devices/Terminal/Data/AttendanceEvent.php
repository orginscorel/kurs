<?php

namespace App\Services\Devices\Terminal\Data;

use Carbon\CarbonImmutable;

/**
 * Terminalden gelen tek okutma — markadan bağımsız normal biçim. Uygulamanın yoklama modeli
 * (App\Models\AttendanceEvent) DEĞİLDİR; PresenceService::ingest() girdisine buradan çevrilir.
 */
final class AttendanceEvent
{
    public function __construct(
        public readonly int $deviceId,
        public readonly string $deviceUserId,
        public readonly CarbonImmutable $occurredAt,
        public readonly VerificationMethod $verificationMethod = VerificationMethod::Unknown,
        public readonly Direction $direction = Direction::Unknown,
        public readonly ?string $rawEventId = null,
        public readonly array $rawPayload = [],
        public readonly ?CarbonImmutable $receivedAt = null,
    ) {}

    /**
     * Yinelenen engelleme anahtarı (attendance_events.idempotency_key, TEKİL, ≤80 karakter).
     * Cihaz gerçek bir kayıt kimliği veriyorsa o kullanılır; yoksa cihaz + kullanıcı + saniye.
     * ZKTeco için biçim geriye uyumludur: "zk:{cihaz}:{kullanıcı}:{unix}".
     */
    public function idempotencyKey(string $prefix): string
    {
        if ($this->rawEventId !== null && $this->rawEventId !== '') {
            return substr($prefix.':'.$this->deviceId.':r:'.preg_replace('/[^A-Za-z0-9_.-]/', '', $this->rawEventId), 0, 80);
        }

        $user = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $this->deviceUserId) ?: '0', 0, 24);

        return $prefix.':'.$this->deviceId.':'.$user.':'.$this->occurredAt->getTimestamp();
    }

    /** PresenceService::ingest() girdisi. */
    public function toIngestPayload(string $prefix): array
    {
        return [
            'identifier' => $this->deviceUserId,
            'identifier_kind' => 'fingerprint',   // eşleme cihaz KULLANICI NUMARASI ile yapılır
            'event_type' => $this->direction->eventType(),
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
            'idempotency_key' => $this->idempotencyKey($prefix),
            'source' => $this->verificationMethod->source(),
        ];
    }
}
