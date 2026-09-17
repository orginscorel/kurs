<?php

namespace App\Services\Messaging\Sms;

/** Sağlayıcı bakiye/kredi sorgusu sonucu. */
final class SmsBalance
{
    public function __construct(
        public readonly bool $success,
        public readonly ?float $credits = null,
        public readonly ?float $money = null,
        public readonly ?string $error = null,
        public readonly ?string $description = null,
    ) {}

    public static function ok(?float $credits, ?float $money = null, ?string $description = null): self
    {
        return new self(true, $credits, $money, null, $description);
    }

    public static function fail(string $error): self
    {
        return new self(false, null, null, $error);
    }

    public function summary(): string
    {
        if (! $this->success) {
            return $this->error ?? 'Bakiye sorgulanamadı.';
        }
        $parts = [];
        if ($this->credits !== null) {
            $parts[] = number_format($this->credits, 0, ',', '.').' SMS kredisi';
        }
        if ($this->money !== null) {
            $parts[] = number_format($this->money, 2, ',', '.').' TL bakiye';
        }
        if ($this->description) {
            $parts[] = $this->description;
        }

        return $parts ? implode(' · ', $parts) : 'Bağlantı başarılı.';
    }

    /** @return array{credits: ?float, money: ?float, description: ?string} */
    public function toArray(): array
    {
        return ['credits' => $this->credits, 'money' => $this->money, 'description' => $this->description];
    }
}
