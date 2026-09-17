<?php

namespace App\Sync\Local;

use Closure;
use Illuminate\Database\SQLiteConnection;

/**
 * Yerel düğüm (SQLite): uygulamadaki MySQL'e özgü ham SQL parçalarını çalışır hale getirir.
 * 1) Sorgu metni yeniden yazılır (TIMESTAMPDIFF birimi, GROUP_CONCAT SEPARATOR, MATCH…AGAINST, LEFT/RIGHT).
 * 2) Eksik fonksiyonlar PDO'ya kaydedilir (CONCAT, DATE_FORMAT, FIELD, REGEXP, IF, GREATEST…).
 * Sunucu MySQL'de hiç devreye girmez.
 */
class SqliteCompatConnection extends SQLiteConnection
{
    private bool $functionsRegistered = false;

    protected function run($query, $bindings, Closure $callback)
    {
        $this->registerFunctions();

        return parent::run(self::rewrite((string) $query), $bindings, $callback);
    }

    public function registerFunctions(): void
    {
        if ($this->functionsRegistered) {
            return;
        }
        $pdo = $this->getPdo();
        if ($pdo instanceof \PDO) {
            SqliteCompat::register($pdo);
            $this->functionsRegistered = true;
        }
    }

    public function setPdo($pdo)
    {
        $this->functionsRegistered = false;

        return parent::setPdo($pdo);
    }

    public static function rewrite(string $sql): string
    {
        if (! preg_match('/TIMESTAMPDIFF|SEPARATOR|AGAINST|RIGHT\s*\(|LEFT\s*\(|TIMEDIFF|INTERVAL|(=|<>|\bIN\s*\()\s*"/i', $sql)) {
            return $sql;
        }
        // MySQL'de çift tırnak metindir; SQLite önce sütun/takma ad sanır ("absent" → SUM(...) AS absent hatası).
        // Yalnız elle yazılmış (tırnaksız) sütunla karşılaştırılan çift tırnaklı değerler tek tırnağa çevrilir;
        // sorgu oluşturucunun "tablo"."sütun" tanımlayıcıları etkilenmez.
        $single = fn (string $v) => "'".str_replace("'", "''", $v)."'";
        $sql = preg_replace_callback('/(?<!["\w.])([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)?)\s*(=|!=|<>)\s*"([^"\\\\]*)"(?!\s*\.)/',
            fn ($m) => $m[1].' '.$m[2].' '.$single($m[3]), $sql);
        $sql = preg_replace_callback('/(?<!["\w.])([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)?)\s+((?:NOT\s+)?IN)\s*\(((?:\s*"[^"\\\\]*"\s*,?)+)\)/i',
            fn ($m) => $m[1].' '.$m[2].' ('.preg_replace_callback('/"([^"\\\\]*)"/', fn ($x) => $single($x[1]), $m[3]).')', $sql);
        // TIMESTAMPDIFF(MINUTE, a, b) → TIMESTAMPDIFF('MINUTE', a, b)
        $sql = preg_replace('/TIMESTAMPDIFF\s*\(\s*(MICROSECOND|SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|QUARTER|YEAR)\s*,/i', "TIMESTAMPDIFF('$1',", $sql);
        // GROUP_CONCAT(DISTINCT x ORDER BY y SEPARATOR ', ') → GROUP_CONCAT(DISTINCT x)  (SQLite 3.26: ayraç/sıra yok)
        $sql = preg_replace('/GROUP_CONCAT\s*\(\s*DISTINCT\s+([^()]+?)(\s+ORDER\s+BY\s+[^()]+?)?\s+SEPARATOR\s+\'[^\']*\'\s*\)/i', 'GROUP_CONCAT(DISTINCT $1)', $sql);
        // GROUP_CONCAT(x ORDER BY y SEPARATOR 's') → GROUP_CONCAT(x, 's')
        $sql = preg_replace('/GROUP_CONCAT\s*\(\s*([^()]+?)(\s+ORDER\s+BY\s+[^()]+?)?\s+SEPARATOR\s+(\'[^\']*\')\s*\)/i', 'GROUP_CONCAT($1, $3)', $sql);
        // MATCH(a, b) AGAINST (? IN BOOLEAN MODE) → mysql_match(?, a, b)
        $sql = preg_replace('/MATCH\s*\(([^()]+)\)\s*AGAINST\s*\(\s*(\?|\'[^\']*\')\s*(IN\s+BOOLEAN\s+MODE)?\s*\)/i', 'mysql_match($2, $1)', $sql);
        // LEFT( / RIGHT( fonksiyon (JOIN değil)
        $sql = preg_replace('/(?<![\w.])(RIGHT|LEFT)\s*\((?!\s*SELECT)/i', 'MY_$1(', $sql);

        return $sql;
    }
}
