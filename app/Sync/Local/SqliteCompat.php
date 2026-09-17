<?php

namespace App\Sync\Local;

/**
 * SQLite'a MySQL fonksiyonları (yalnız yerel düğüm ve uyumluluk testleri).
 */
final class SqliteCompat
{
    public static function register(\PDO $pdo): void
    {
        $f = fn (string $name, callable $cb, int $args = -1) => $pdo->sqliteCreateFunction($name, $cb, $args);

        $f('CONCAT', fn (...$a) => in_array(null, $a, true) ? null : implode('', $a));
        $f('CONCAT_WS', function ($sep, ...$a) {
            return implode((string) $sep, array_filter($a, fn ($v) => $v !== null));
        });
        $f('IF', fn ($c, $a, $b) => $c ? $a : $b, 3);
        $f('FIELD', function ($needle, ...$list) {
            foreach ($list as $i => $v) {
                if ((string) $v === (string) $needle) {
                    return $i + 1;
                }
            }

            return 0;
        });
        $f('GREATEST', fn (...$a) => in_array(null, $a, true) ? null : max($a));
        $f('LEAST', fn (...$a) => in_array(null, $a, true) ? null : min($a));
        $f('NOW', fn () => now()->format('Y-m-d H:i:s'), 0);
        $f('CURDATE', fn () => now()->toDateString(), 0);
        $f('CURRENT_DATE', fn () => now()->toDateString(), 0);
        $f('UNIX_TIMESTAMP', fn ($d = null) => $d === null ? time() : strtotime((string) $d));
        $f('YEAR', fn ($d) => $d === null ? null : (int) substr((string) $d, 0, 4), 1);
        $f('MONTH', fn ($d) => $d === null ? null : (int) substr((string) $d, 5, 2), 1);
        $f('DAY', fn ($d) => $d === null ? null : (int) substr((string) $d, 8, 2), 1);
        $f('HOUR', fn ($d) => $d === null ? null : (int) date('G', strtotime((string) $d)), 1);
        $f('MINUTE', fn ($d) => $d === null ? null : (int) date('i', strtotime((string) $d)), 1);
        $f('WEEKDAY', fn ($d) => $d === null ? null : ((int) date('N', strtotime((string) $d))) - 1, 1);
        $f('DAYOFWEEK', fn ($d) => $d === null ? null : ((int) date('w', strtotime((string) $d))) + 1, 1);
        $f('DATEDIFF', fn ($a, $b) => ($a === null || $b === null) ? null
            : (int) round((strtotime(substr((string) $a, 0, 10)) - strtotime(substr((string) $b, 0, 10))) / 86400), 2);
        $f('TIMESTAMPDIFF', function ($unit, $a, $b) {
            if ($a === null || $b === null) {
                return null;
            }
            $s = strtotime((string) $b) - strtotime((string) $a);

            return match (strtoupper((string) $unit)) {
                'SECOND' => $s,
                'MINUTE' => intdiv($s, 60),
                'HOUR' => intdiv($s, 3600),
                'DAY' => intdiv($s, 86400),
                'WEEK' => intdiv($s, 604800),
                'MONTH' => self::monthDiff((string) $a, (string) $b),
                'YEAR' => intdiv(self::monthDiff((string) $a, (string) $b), 12),
                default => $s,
            };
        }, 3);
        $f('TIMEDIFF', function ($a, $b) {
            if ($a === null || $b === null) {
                return null;
            }
            $s = self::seconds((string) $a) - self::seconds((string) $b);
            $sign = $s < 0 ? '-' : '';
            $s = abs($s);

            return sprintf('%s%02d:%02d:%02d', $sign, intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
        }, 2);
        $f('TIME_TO_SEC', function ($t) {
            if ($t === null) {
                return null;
            }
            $neg = str_starts_with((string) $t, '-');
            [$h, $m, $s] = array_pad(array_map('intval', explode(':', ltrim((string) $t, '-'))), 3, 0);
            $v = $h * 3600 + $m * 60 + $s;

            return $neg ? -$v : $v;
        }, 1);
        $f('DATE_FORMAT', function ($d, $fmt) {
            if ($d === null) {
                return null;
            }
            $ts = strtotime((string) $d);
            $map = ['%Y' => 'Y', '%y' => 'y', '%m' => 'm', '%c' => 'n', '%d' => 'd', '%e' => 'j', '%H' => 'H', '%k' => 'G',
                '%i' => 'i', '%s' => 's', '%S' => 's', '%M' => 'F', '%b' => 'M', '%W' => 'l', '%a' => 'D', '%u' => 'W', '%v' => 'W', '%x' => 'o', '%%' => '%'];
            $out = '';
            $fmt = (string) $fmt;
            for ($i = 0; $i < strlen($fmt); $i++) {
                $pair = substr($fmt, $i, 2);
                if (isset($map[$pair])) {
                    $out .= $map[$pair] === '%' ? '%' : date($map[$pair], $ts);
                    $i++;
                } else {
                    $out .= $fmt[$i];
                }
            }

            return $out;
        }, 2);
        $f('JSON_UNQUOTE', fn ($v) => is_string($v) && strlen($v) >= 2 && $v[0] === '"' ? json_decode($v) : $v, 1);
        $f('REGEXP', fn ($pattern, $value) => $value !== null && @preg_match('~'.str_replace('~', '\~', (string) $pattern).'~u', (string) $value) === 1 ? 1 : 0, 2);
        $f('REGEXP_REPLACE', fn ($value, $pattern, $repl) => $value === null ? null
            : preg_replace('~'.str_replace('~', '\~', (string) $pattern).'~u', (string) $repl, (string) $value), 3);
        $f('MY_RIGHT', fn ($s, $n) => $s === null ? null : mb_substr((string) $s, -max(0, (int) $n) ?: mb_strlen((string) $s)), 2);
        $f('MY_LEFT', fn ($s, $n) => $s === null ? null : mb_substr((string) $s, 0, max(0, (int) $n)), 2);
        $f('LPAD', fn ($s, $n, $p) => $s === null ? null : str_pad((string) $s, (int) $n, (string) $p, STR_PAD_LEFT), 3);
        $f('RPAD', fn ($s, $n, $p) => $s === null ? null : str_pad((string) $s, (int) $n, (string) $p, STR_PAD_RIGHT), 3);
        // MATCH(cols) AGAINST(? IN BOOLEAN MODE): "+kelime*" öneklerinin hepsi sütunlardan birinde geçmeli
        $f('mysql_match', function ($query, ...$cols) {
            $hay = mb_strtolower(implode(' ', array_map('strval', $cols)));
            $words = preg_split('/\s+/u', trim((string) $query)) ?: [];
            $score = 0;
            foreach ($words as $w) {
                $w = mb_strtolower(trim($w, '+-*"<>()~@'));
                if ($w === '') {
                    continue;
                }
                if (! str_contains($hay, $w)) {
                    return 0;
                }
                $score++;
            }

            return $score;
        });
    }

    private static function monthDiff(string $a, string $b): int
    {
        $da = new \DateTimeImmutable($a);
        $db = new \DateTimeImmutable($b);
        $diff = $da->diff($db);
        $months = $diff->y * 12 + $diff->m;

        return $diff->invert ? -$months : $months;
    }

    private static function seconds(string $t): int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $t)) {
            return (int) strtotime($t);
        }
        [$h, $m, $s] = array_pad(array_map('intval', explode(':', $t)), 3, 0);

        return $h * 3600 + $m * 60 + $s;
    }
}
