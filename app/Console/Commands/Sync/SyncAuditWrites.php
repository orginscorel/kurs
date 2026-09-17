<?php

namespace App\Console\Commands\Sync;

use App\Sync\SyncRegistry;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * Eloquent olayı üretmeyen yazmaları tarar (DB::table(...)->insert/update/delete…, sorgu üzerinden toplu
 * update/delete/increment) ve eşitleme açısından sınıflandırır:
 *   - eşitlenen tablo  → süpürücü yakalar (en geç sonraki süpürmede), anında değil
 *   - eşitlenmeyen     → yerel/sistem/türetilmiş veri, eşitleme dışı
 */
class SyncAuditWrites extends Command
{
    protected $signature = 'kurs:sync-audit-writes {--json= : sonucu bu dosyaya yaz} {--only-synced : yalnız eşitlenen tablolar}';

    protected $description = 'Olaysız veritabanı yazmalarını tarar ve eşitleme sınıflandırmasıyla listeler';

    private const WRITE = '(insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|increment|decrement|incrementEach|decrementEach|truncate|forceDelete)';

    public function handle(): int
    {
        $rows = [];
        $finder = (new Finder)->files()->in([app_path(), database_path('migrations')])->name('*.php');
        foreach ($finder as $file) {
            $path = str_replace(base_path().'/', '', $file->getRealPath());
            if (str_starts_with($path, 'app/Sync/')) {
                continue;   // eşitleme altyapısının kendi yazmaları
            }
            $code = $file->getContents();
            foreach ($this->statements($code) as [$stmt, $line]) {
                if (! preg_match('/->'.self::WRITE.'\s*\(/', $stmt, $wm)) {
                    continue;
                }
                $table = null;
                $via = null;
                if (preg_match('/DB::table\(\s*[\'"]([a-z_]+)(?:\s+as\s+\w+)?[\'"]/i', $stmt, $m)) {
                    $table = $m[1];
                    $via = 'DB::table';
                } elseif (preg_match('/\b([A-Z]\w+)::(?:query|where\w*|withoutGlobalScopes|withTrashed|onlyTrashed|whereKey)\(/', $stmt, $m)
                    && ! in_array($m[1], ['DB', 'Schema', 'Route', 'Cache', 'Storage', 'Str', 'Arr', 'Carbon', 'CarbonImmutable', 'Http'], true)) {
                    $class = $this->resolveClass($code, $m[1]);
                    if ($class && is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
                        // Model::query()->...->update() toplu yazmadır (olay yok); tek model ->save() değil
                        if (! preg_match('/->(where|whereIn|whereKey|whereNull|whereNotNull|query)\b/', $stmt)) {
                            continue;
                        }
                        if (preg_match('/->(first|find|findOrFail|firstOrFail|firstOrCreate|create|updateOrCreate)\(/', $stmt)) {
                            continue;
                        }
                        $table = (new $class)->getTable();
                        $via = $m[1].' (sorgu)';
                    }
                } elseif (preg_match('/->(allocations|installments|guardians|classGroups|tags|subjects|students|teachers|roles|permissions)\(\)->(attach|detach|sync|syncWithoutDetaching|updateExistingPivot)\(/', $stmt, $pm)) {
                    $table = 'pivot:'.$pm[1];
                    $via = 'pivot '.$pm[2];
                }
                if (! $table) {
                    continue;
                }
                $def = SyncRegistry::get($table);
                $kind = $def?->kind ?? (str_starts_with($table, 'pivot:') ? 'pivot?' : 'tanımsız');
                $synced = $def?->isSynced() ?? str_starts_with($table, 'pivot:');
                if ($this->option('only-synced') && ! $synced) {
                    continue;
                }
                $rows[] = [
                    'file' => $path.':'.$line,
                    'table' => $table,
                    'via' => $via.' ->'.$wm[1],
                    'kind' => $kind,
                    'handling' => str_starts_with($path, 'database/migrations')
                        ? 'migration (kurs:sync-prepare / süpürücü baseline)'
                        : ($synced ? 'süpürücü yakalar (olaysız yazma)' : 'eşitleme dışı ('.$kind.')'),
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$a['handling'], $a['table'], $a['file']] <=> [$b['handling'], $b['table'], $b['file']]);
        $this->table(['Dosya', 'Tablo', 'Yazma', 'Tür', 'Ele alınış'], array_map('array_values', $rows));
        $synced = count(array_filter($rows, fn ($r) => str_starts_with($r['handling'], 'süpürücü')));
        $this->info(sprintf('%d olaysız yazma; %d tanesi eşitlenen tabloda (süpürücüyle), %d eşitleme dışı.', count($rows), $synced, count($rows) - $synced));

        if ($out = $this->option('json')) {
            file_put_contents($out, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    /** @return list<array{0: string, 1: int}> ifade + başlangıç satırı */
    private function statements(string $code): array
    {
        $out = [];
        $buf = '';
        $line = 1;
        $start = 1;
        $depth = 0;
        foreach (token_get_all($code) as $tok) {
            $text = is_array($tok) ? $tok[1] : $tok;
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $line += substr_count($text, "\n");

                continue;
            }
            if (trim($buf) === '' && trim($text) !== '') {
                $start = $line;
            }
            if ($text === '(' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === ']') {
                $depth--;
            }
            $buf .= $text;
            $line += substr_count($text, "\n");
            if ($text === ';' || $text === '{' || $text === '}') {
                if (str_contains($buf, '->')) {
                    $out[] = [$buf, $start];
                }
                $buf = '';
                $depth = 0;
            }
        }

        return $out;
    }

    private function resolveClass(string $code, string $short): ?string
    {
        if (preg_match('/^use\s+([\w\\\\]+\\\\'.preg_quote($short, '/').')\s*;/m', $code, $m)) {
            return $m[1];
        }
        $guess = 'App\\Models\\'.$short;

        return class_exists($guess) ? $guess : null;
    }
}
