<?php

namespace App\Services\Devices\Terminal\Data;

enum Direction: string
{
    case Entry = 'entry';
    case Exit = 'exit';
    case Unknown = 'unknown';

    /** PresenceService olay tipi: bilinmeyen yön AUTO (öğrenci içerideyse çıkış, değilse giriş). */
    public function eventType(): string
    {
        return match ($this) {
            self::Entry => 'ENTRY',
            self::Exit => 'EXIT',
            self::Unknown => 'AUTO',
        };
    }

    public static function fromEventType(string $type): self
    {
        return match (strtoupper($type)) {
            'ENTRY' => self::Entry,
            'EXIT' => self::Exit,
            default => self::Unknown,
        };
    }
}
