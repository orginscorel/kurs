<?php

namespace Tests\Support;

use Closure;
use Illuminate\Database\SQLiteConnection;

/**
 * Yalnız testte: MySQL'e özgü birkaç ham SQL kalıbını SQLite'ın anlayacağı biçime çevirir; böylece
 * uç taraması bu sorgularda takılmadan asıl kontrol edilen koda (Eloquent öznitelik erişimi) ulaşır.
 *  - TIMESTAMPDIFF(MINUTE, a, b)          → TIMESTAMPDIFF('MINUTE', a, b)  (işlev PHP ile kaydedilir)
 *  - GROUP_CONCAT(x ORDER BY … SEPARATOR 's') → GROUP_CONCAT(x, 's')
 * Diğer işlevler (CONCAT, GREATEST, FIELD, DATE_FORMAT…) `register()` ile PDO'ya eklenir.
 */
class MysqlCompatSqliteConnection extends SQLiteConnection
{
    protected function run($query, $bindings, Closure $callback)
    {
        $query = preg_replace('/TIMESTAMPDIFF\(\s*(SECOND|MINUTE|HOUR|DAY)\s*,/i', "TIMESTAMPDIFF('$1',", $query);
        $query = preg_replace_callback('/GROUP_CONCAT\((DISTINCT\s+)?(.+?)(\s+ORDER\s+BY\s+[^)]*?)?(\s+SEPARATOR\s+(\'[^\']*\'))?\)/is', function ($m) {
            if (empty($m[3]) && empty($m[4])) {
                return $m[0];
            }
            if (! empty($m[1])) {
                return 'GROUP_CONCAT(DISTINCT '.$m[2].')';
            }

            return 'GROUP_CONCAT('.$m[2].(isset($m[5]) ? ', '.$m[5] : '').')';
        }, $query);

        return parent::run($query, $bindings, $callback);
    }

    public static function register(\PDO $pdo): void
    {
        $anyNull = fn (array $a) => in_array(null, $a, true);
        $pdo->sqliteCreateFunction('CONCAT', fn (...$a) => $anyNull($a) ? null : implode('', $a));
        $pdo->sqliteCreateFunction('GREATEST', fn (...$a) => $anyNull($a) ? null : max($a));
        $pdo->sqliteCreateFunction('LEAST', fn (...$a) => $anyNull($a) ? null : min($a));
        $pdo->sqliteCreateFunction('FIELD', function ($v, ...$list) {
            $i = array_search($v, $list);

            return $i === false ? 0 : $i + 1;
        });
        $pdo->sqliteCreateFunction('NOW', fn () => now()->format('Y-m-d H:i:s'), 0);
        $pdo->sqliteCreateFunction('JSON_UNQUOTE', fn ($v) => $v, 1);
        $pdo->sqliteCreateFunction('TIMESTAMPDIFF', function ($unit, $a, $b) {
            if ($a === null || $b === null) {
                return null;
            }

            return intdiv(strtotime($b) - strtotime($a), ['SECOND' => 1, 'MINUTE' => 60, 'HOUR' => 3600, 'DAY' => 86400][strtoupper($unit)] ?? 1);
        }, 3);
        $pdo->sqliteCreateFunction('DATEDIFF', fn ($a, $b) => ($a === null || $b === null) ? null
            : (int) round((strtotime(substr($a, 0, 10)) - strtotime(substr($b, 0, 10))) / 86400), 2);
        $pdo->sqliteCreateFunction('TIME_TO_SEC', function ($t) {
            if ($t === null) {
                return null;
            }
            $p = array_map('intval', explode(':', (string) $t));

            return ($p[0] ?? 0) * 3600 + ($p[1] ?? 0) * 60 + ($p[2] ?? 0);
        }, 1);
        $pdo->sqliteCreateFunction('TIMEDIFF', function ($a, $b) {
            if ($a === null || $b === null) {
                return null;
            }
            $d = strtotime($a) - strtotime($b);
            $sign = $d < 0 ? '-' : '';
            $d = abs($d);

            return sprintf('%s%02d:%02d:%02d', $sign, intdiv($d, 3600), intdiv($d % 3600, 60), $d % 60);
        }, 2);
        $pdo->sqliteCreateFunction('WEEKDAY', fn ($d) => $d === null ? null : ((int) date('N', strtotime($d)) - 1), 1);
        $pdo->sqliteCreateFunction('DATE_FORMAT', function ($d, $format) {
            if ($d === null) {
                return null;
            }
            $ts = strtotime($d);
            $map = ['Y' => 'Y', 'm' => 'm', 'd' => 'd', 'H' => 'H', 'i' => 'i', 's' => 's', 'u' => 'W', 'v' => 'W', 'x' => 'o', 'e' => 'j', 'c' => 'n', 'k' => 'G'];

            return preg_replace_callback('/%([a-zA-Z%])/', fn ($m) => $m[1] === '%' ? '%' : (isset($map[$m[1]]) ? date($map[$m[1]], $ts) : $m[0]), $format);
        }, 2);
    }
}
