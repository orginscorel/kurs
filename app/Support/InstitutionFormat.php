<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Kurum ayarlarının (institution.*) belge ve zamanlama davranışına yansıması:
 * saat dilimi, para birimi simgesi, vergi/web alt bilgisi.
 */
final class InstitutionFormat
{
    public const DEFAULT_TIMEZONE = 'Europe/Istanbul';

    public const CURRENCY_SYMBOLS = ['TRY' => '₺', 'TL' => '₺', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];

    /** Geçerli IANA saat dilimi; ayar boş/bozuksa İstanbul. */
    public static function timezone(?int $branchId = null): string
    {
        return self::validTimezone(Settings::get('institution.timezone', self::DEFAULT_TIMEZONE, $branchId));
    }

    public static function validTimezone(mixed $tz): string
    {
        return is_string($tz) && in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : self::DEFAULT_TIMEZONE;
    }

    /** Kurum saatinde şimdi. */
    public static function now(?int $branchId = null): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone($branchId));
    }

    /** Belge üzerindeki "üretildi" zamanı (kurum saat diliminde). */
    public static function stamp(?array $institution = null, string $format = 'd.m.Y H:i'): string
    {
        $tz = self::validTimezone($institution['timezone'] ?? null);

        return CarbonImmutable::now($tz)->format($format);
    }

    /** TRY → ₺, USD → $ …; bilinmeyen kod olduğu gibi. */
    public static function currencySymbol(mixed $code): string
    {
        $code = is_string($code) && trim($code) !== '' ? mb_strtoupper(trim($code)) : 'TRY';

        return self::CURRENCY_SYMBOLS[$code] ?? $code;
    }

    /** "Erbaa V.D. · 1234567890" (boşsa null). */
    public static function taxLine(array $institution): ?string
    {
        $office = trim((string) ($institution['tax_office'] ?? ''));
        $number = trim((string) ($institution['tax_number'] ?? ''));
        $parts = array_filter([$office !== '' ? $office.' V.D.' : null, $number !== '' ? 'VKN '.$number : null]);

        return $parts ? implode(' · ', $parts) : null;
    }

    /** Alt bilgi için web sitesi (şema öneki atılır). */
    public static function website(array $institution): ?string
    {
        $site = trim((string) ($institution['website'] ?? ''));

        return $site !== '' ? preg_replace('#^https?://#i', '', rtrim($site, '/')) : null;
    }

    /** Alt bilgi satırı: vergi + web (boşsa null). */
    public static function footerLine(array $institution): ?string
    {
        $parts = array_filter([self::taxLine($institution), self::website($institution)]);

        return $parts ? implode(' · ', $parts) : null;
    }
}
