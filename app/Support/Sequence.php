<?php

namespace App\Support;

use App\Sync\SyncNumbers;
use Illuminate\Support\Facades\DB;

/**
 * Çakışmasız belge numarası: MKB-2026-000123
 * Satır kilidi (SELECT … FOR UPDATE) ile iki eşzamanlı tahsilat aynı numarayı alamaz.
 * Çağıran taraf zaten bir transaction içinde olmalıdır.
 */
class Sequence
{
    /** Yalın sayaç değeri (ör. öğrenci numarası için): yıl başına sıfırlanmaz. */
    public static function nextNumber(string $name, int $start, ?int $branchId = null): int
    {
        $branchId ??= app(BranchContext::class)->id();

        // Eşitleme: sunucuda cihaz komutu yeniden yürütülüyorsa cihazın numarası, yerel düğümde ayrılmış blok
        if (($synced = SyncNumbers::forNumber($name, $branchId)) !== null) {
            SyncNumbers::issued($name, (string) $synced);

            return $synced;
        }

        return DB::transaction(function () use ($name, $start, $branchId) {
            DB::table('sequences')->insertOrIgnore(['branch_id' => $branchId, 'name' => $name, 'year' => 0, 'last_value' => $start - 1]);
            $row = DB::table('sequences')->where('branch_id', $branchId)->where('name', $name)->where('year', 0)->lockForUpdate()->first();
            $value = $row->last_value + 1;
            DB::table('sequences')->where('id', $row->id)->update(['last_value' => $value]);

            return (int) $value;
        });
    }

    public static function next(string $name, string $prefix, ?int $branchId = null, int $pad = 6): string
    {
        $branchId ??= app(BranchContext::class)->id();

        // Eşitleme: cihaz numarası (yeniden yürütme) ya da yerel düğümde cihaz önekli sayaç (MKB-D2-…)
        $sync = SyncNumbers::forDocument($name, $prefix);
        if (isset($sync['value'])) {
            return $sync['value'];
        }
        $value = self::generate($sync['name'], $sync['prefix'], $branchId, $pad);
        SyncNumbers::issued($name, $value);

        return $value;
    }

    private static function generate(string $name, string $prefix, ?int $branchId, int $pad): string
    {
        $year = (int) now()->format('Y');

        return DB::transaction(function () use ($name, $prefix, $branchId, $year, $pad) {
            $row = DB::table('sequences')
                ->where('branch_id', $branchId)->where('name', $name)->where('year', $year)
                ->lockForUpdate()->first();

            if (! $row) {
                DB::table('sequences')->insertOrIgnore([
                    'branch_id' => $branchId, 'name' => $name, 'year' => $year, 'last_value' => 0,
                ]);
                $row = DB::table('sequences')
                    ->where('branch_id', $branchId)->where('name', $name)->where('year', $year)
                    ->lockForUpdate()->first();
            }

            $value = $row->last_value + 1;
            DB::table('sequences')->where('id', $row->id)->update(['last_value' => $value]);

            return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $value, $pad, '0', STR_PAD_LEFT));
        });
    }
}
