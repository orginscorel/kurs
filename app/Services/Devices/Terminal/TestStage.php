<?php

namespace App\Services\Devices\Terminal;

/** Testin tek satırı (ekrandaki durum tablosu: Ağ / TCP / Protokol / Cihaz tanıma). */
final class TestStage
{
    public const PASS = 'basarili';

    public const FAIL = 'basarisiz';

    public const SKIP = 'denenmedi';

    public const NOT_VERIFIED = 'dogrulanamadi';

    public function __construct(
        public readonly string $label,
        public readonly string $status,
        public readonly string $detail = '',
        public readonly ?string $hint = null,
    ) {}

    public static function pass(string $label, string $detail = ''): self
    {
        return new self($label, self::PASS, $detail);
    }

    public static function fail(string $label, string $detail, ?string $hint = null): self
    {
        return new self($label, self::FAIL, $detail, $hint ?: null);
    }

    public static function skip(string $label, string $detail): self
    {
        return new self($label, self::SKIP, $detail);
    }

    public static function notVerified(string $label, string $detail, ?string $hint = null): self
    {
        return new self($label, self::NOT_VERIFIED, $detail, $hint ?: null);
    }

    public function toArray(): array
    {
        return ['etiket' => $this->label, 'durum' => $this->status, 'ayrinti' => $this->detail, 'oneri' => $this->hint];
    }
}
