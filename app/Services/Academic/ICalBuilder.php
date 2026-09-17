<?php

namespace App\Services\Academic;

/**
 * RFC 5545 iCalendar çıktısı (saf). Saatler Europe/Istanbul yerel saati olarak TZID ile yazılır;
 * Türkiye yaz saati uygulamadığı için VTIMEZONE sabit +03:00.
 */
final class ICalBuilder
{
    /**
     * @param  list<array{uid:string, title:string, date:string, end_date?:?string, start?:?string, end?:?string,
     *               location?:?string, description?:?string, cancelled?:bool, updated?:?string}>  $events
     *         date/end_date 'Y-m-d', start/end 'H:i' (yoksa tüm gün)
     */
    public static function build(string $calendarName, array $events, string $host = 'kurs.bogahostdeveloper.com.tr'): string
    {
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Erbaa Bilgi Egitim//Ders Programi//TR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escape($calendarName), 'X-WR-TIMEZONE:Europe/Istanbul', 'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H',
            'BEGIN:VTIMEZONE', 'TZID:Europe/Istanbul', 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:+0300', 'TZOFFSETTO:+0300', 'TZNAME:+03', 'END:STANDARD', 'END:VTIMEZONE',
        ];
        $stamp = gmdate('Ymd\THis\Z');

        foreach ($events as $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.self::escape($e['uid']).'@'.$host;
            $lines[] = 'DTSTAMP:'.(isset($e['updated']) && $e['updated'] ? gmdate('Ymd\THis\Z', strtotime($e['updated'])) : $stamp);
            $date = str_replace('-', '', $e['date']);
            if (! empty($e['start'])) {
                $lines[] = 'DTSTART;TZID=Europe/Istanbul:'.$date.'T'.str_replace(':', '', substr($e['start'], 0, 5)).'00';
                $lines[] = 'DTEND;TZID=Europe/Istanbul:'.$date.'T'.str_replace(':', '', substr($e['end'] ?? $e['start'], 0, 5)).'00';
            } else {
                $endDate = new \DateTimeImmutable(($e['end_date'] ?? null) ?: $e['date']);
                $lines[] = 'DTSTART;VALUE=DATE:'.$date;
                $lines[] = 'DTEND;VALUE=DATE:'.$endDate->modify('+1 day')->format('Ymd'); // DTEND tüm günde hariç
            }
            $lines[] = 'SUMMARY:'.self::escape(($e['cancelled'] ?? false ? 'İPTAL: ' : '').$e['title']);
            if (! empty($e['location'])) {
                $lines[] = 'LOCATION:'.self::escape($e['location']);
            }
            if (! empty($e['description'])) {
                $lines[] = 'DESCRIPTION:'.self::escape($e['description']);
            }
            $lines[] = 'STATUS:'.(($e['cancelled'] ?? false) ? 'CANCELLED' : 'CONFIRMED');
            $lines[] = 'TRANSP:'.(! empty($e['start']) ? 'OPAQUE' : 'TRANSPARENT');
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines))."\r\n";
    }

    public static function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\\;', '\\,', '\\n', '\\n'], $text);
    }

    /** 75 oktet satır katlama (çok baytlı karakteri bölmeden). */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $current = '';
        foreach (mb_str_split($line) as $ch) {
            $limit = $out === '' ? 75 : 74;
            if (strlen($current.$ch) > $limit) {
                $out .= ($out === '' ? '' : "\r\n ").$current;
                $current = '';
            }
            $current .= $ch;
        }

        return $out.($out === '' ? '' : "\r\n ").$current;
    }
}
