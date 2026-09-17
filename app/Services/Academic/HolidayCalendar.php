<?php

namespace App\Services\Academic;

/**
 * Tatil aralıkları üzerinde saf tarih mantığı (veritabanı yok). Tarihler 'Y-m-d' dizesidir
 * (sözlük sırası = kronolojik sıra). Aralıklar uçlar DAHİL.
 */
final class HolidayCalendar
{
    /** @param list<array{id:int, name:string, starts_on:string, ends_on:string, cancel_sessions?:bool}> $holidays */
    public function __construct(private readonly array $holidays) {}

    public static function overlaps(string $aFrom, string $aTo, string $bFrom, string $bTo): bool
    {
        return $aFrom <= $bTo && $bFrom <= $aTo;
    }

    /** Tarihe denk gelen ve ders iptal eden ilk tatil. */
    public function find(string $date): ?array
    {
        foreach ($this->holidays as $h) {
            if (($h['cancel_sessions'] ?? true) && $date >= $h['starts_on'] && $date <= $h['ends_on']) {
                return $h;
            }
        }

        return null;
    }

    /** @return list<array> verilen aralıkla kesişen tatiller */
    public function between(string $from, string $to): array
    {
        return array_values(array_filter($this->holidays, fn ($h) => self::overlaps($h['starts_on'], $h['ends_on'], $from, $to)));
    }

    public static function reason(array $holiday): string
    {
        return mb_substr('Tatil: '.$holiday['name'], 0, 300);
    }
}
