<?php

namespace App\Services\Devices\Terminal;

/**
 * Sürücü sonucu: durum + Türkçe mesaj/öneri + (yalnız Ok ise) veri.
 *
 * @template T
 */
final class DriverResult
{
    /** @param  T|null  $data */
    public function __construct(
        public readonly DriverStatus $status,
        public readonly string $message,
        public readonly string $hint = '',
        public readonly mixed $data = null,
        public readonly array $context = [],
    ) {}

    public static function ok(mixed $data, string $message = 'Başarılı.'): self
    {
        return new self(DriverStatus::Ok, $message, '', $data);
    }

    public static function notImplemented(string $message, string $hint = ''): self
    {
        return new self(DriverStatus::ProtocolNotImplemented, $message, $hint);
    }

    public static function unsupported(string $message, string $hint = ''): self
    {
        return new self(DriverStatus::Unsupported, $message, $hint);
    }

    public function isOk(): bool
    {
        return $this->status === DriverStatus::Ok;
    }

    public function toArray(): array
    {
        return array_filter([
            'durum' => $this->status->value,
            'durum_etiketi' => $this->status->label(),
            'mesaj' => $this->message,
            'oneri' => $this->hint !== '' ? $this->hint : null,
            'ayrinti' => $this->context ?: null,
        ], fn ($v) => $v !== null);
    }
}
