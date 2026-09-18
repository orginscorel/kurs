<?php

namespace App\Services\Devices\Terminal\Data;

enum VerificationMethod: string
{
    case Fingerprint = 'fingerprint';
    case Face = 'face';
    case Palm = 'palm';
    case Card = 'card';
    case Password = 'password';
    case Unknown = 'unknown';

    /** attendance_events.source değeri (mevcut sözlükle uyumlu: kart = rfid). */
    public function source(): string
    {
        return match ($this) {
            self::Card => 'rfid',
            self::Unknown => 'fingerprint',
            default => $this->value,
        };
    }
}
