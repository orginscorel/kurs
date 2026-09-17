<?php

namespace App\Services\Devices\Zk;

use Carbon\CarbonImmutable;

/** Cihazdan çekilen tek bir okutma (attlog) kaydı. */
final class ZkAttendanceRecord
{
    public function __construct(
        public readonly string $userId,          // cihaz kullanıcı numarası
        public readonly CarbonImmutable $timestamp,
        public readonly int $status = 0,         // punch: 0 giriş, 1 çıkış, 2..5 mola/mesai
        public readonly int $verify = 1,         // 0 şifre, 1 parmak, 2/4 kart, 15 yüz
        public readonly int $uid = 0,
    ) {}

    /** Cihazın punch koduna göre yön; tanımsızsa 'AUTO' (içeride/dışarıda durumuna bakılır). */
    public function direction(array $map): string
    {
        return $map[$this->status] ?? 'AUTO';
    }

    /** attendance_events.source değeri. */
    public function source(array $map): string
    {
        return $map[$this->verify] ?? 'fingerprint';
    }

    public function toArray(): array
    {
        return [
            'kullanici_no' => $this->userId,
            'zaman' => $this->timestamp->format('Y-m-d H:i:s'),
            'punch' => $this->status,
            'dogrulama' => $this->verify,
            'uid' => $this->uid,
        ];
    }
}
