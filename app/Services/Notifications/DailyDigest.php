<?php

namespace App\Services\Notifications;

/**
 * Yönetici günlük özetleri (08:00 sabah, 20:00 akşam) için saf metin üretimi. Sayılar komutta
 * veritabanından toplanır; biçimlendirme/karar burada (birim testli).
 */
class DailyDigest
{
    /**
     * @param array{expected_today:int, absent_yesterday:int, absent_yesterday_lessons:int, due_today_count:int,
     *              due_today_amount:string, overdue_count:int, high_risk:int, date_label:string} $s
     * @return array{title:string, body:string, type:string}
     */
    public static function morning(array $s): array
    {
        $lines = [
            "Bugün beklenen öğrenci: {$s['expected_today']}",
            $s['absent_yesterday'] > 0
                ? "Dün devamsızlık: {$s['absent_yesterday']} öğrenci ({$s['absent_yesterday_lessons']} ders)"
                : 'Dün devamsızlık yok',
            $s['due_today_count'] > 0
                ? "Bugün vadeli tahsilat: {$s['due_today_count']} taksit, ".self::money($s['due_today_amount']).' TL'
                : 'Bugün vadesi gelen taksit yok',
            "Yüksek riskli öğrenci: {$s['high_risk']}",
        ];
        if (($s['overdue_count'] ?? 0) > 0) {
            $lines[] = "Gecikmiş taksit: {$s['overdue_count']}";
        }

        $attention = $s['high_risk'] > 0 || $s['absent_yesterday'] > 0;

        return ['title' => "Günaydın · {$s['date_label']} özeti", 'body' => implode("\n", $lines), 'type' => $attention ? 'warning' : 'info'];
    }

    /**
     * @param array{expected_today:int, arrived_today:int, no_show_names:list<string>, absent_today:int,
     *              collected_count:int, collected_amount:string, voided_count:int, date_label:string} $s
     * @return array{title:string, body:string, type:string}
     */
    public static function evening(array $s): array
    {
        $noShow = max(0, $s['expected_today'] - $s['arrived_today']);
        $names = array_slice($s['no_show_names'], 0, 5);
        $more = $noShow - count($names);

        $lines = [
            $noShow > 0
                ? "Bugün gelmeyen: {$noShow} öğrenci".($names ? ' — '.implode(', ', $names).($more > 0 ? " ve {$more} öğrenci daha" : '') : '')
                : 'Beklenen öğrencilerin tamamı geldi',
            "Ders devamsızlığı: {$s['absent_today']} öğrenci",
            $s['collected_count'] > 0
                ? "Tahsilat: {$s['collected_count']} işlem, ".self::money($s['collected_amount']).' TL'
                : 'Bugün tahsilat yapılmadı',
        ];
        if ($s['voided_count'] > 0) {
            $lines[] = "İptal edilen tahsilat: {$s['voided_count']}";
        }

        return ['title' => "Gün sonu · {$s['date_label']}", 'body' => implode("\n", $lines), 'type' => $noShow > 0 ? 'attendance' : 'success'];
    }

    /** bcmath string → "12.345,50" (float kullanmadan). */
    public static function money(string $amount): string
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');
        [$int, $dec] = array_pad(explode('.', $amount, 2), 2, '00');
        $dec = str_pad(substr($dec, 0, 2), 2, '0');
        $int = ltrim($int, '0') === '' ? '0' : ltrim($int, '0');
        $grouped = strrev(implode('.', str_split(strrev($int), 3)));

        return ($negative ? '-' : '').$grouped.','.$dec;
    }
}
