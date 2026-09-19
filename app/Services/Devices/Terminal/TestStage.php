<?php

namespace App\Services\Devices\Terminal;

/** Testin tek satırı (ekrandaki durum tablosu: Ağ / TCP / Protokol / Cihaz tanıma). */
final class TestStage
{
    public const PASS = 'basarili';

    public const FAIL = 'basarisiz';

    public const SKIP = 'denenmedi';

    /** Protokol sürücüsü henüz doğrulanmadı — "başarısız" DEĞİL. */
    public const NOT_VERIFIED = 'dogrulama_bekliyor';

    /** Önceki aşama doğrulanmadığı için bekliyor (ör. cihaz tanıma yalnız geçerli protokol yanıtıyla). */
    public const WAITING = 'bekliyor';

    /** Teknik ayrıntı satırı için İngilizce/Türkçe durum adı. */
    public const TECH = [self::PASS => 'Başarılı', self::FAIL => 'Başarısız', self::SKIP => 'Denenmedi', self::NOT_VERIFIED => 'Doğrulama bekliyor', self::WAITING => 'Bekliyor'];

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

    public static function waiting(string $label, string $detail): self
    {
        return new self($label, self::WAITING, $detail);
    }

    public function toArray(): array
    {
        return ['etiket' => $this->label, 'durum' => $this->status, 'ayrinti' => $this->detail, 'oneri' => $this->hint];
    }
}
