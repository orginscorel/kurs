<?php

namespace App\Services\Discipline;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verilen günde uzaklaştırmada olan öğrenciler (yoklama bağlantısı). Yürürlükteki ve itirazdaki
 * uzaklaştırmalar sayılır (itiraz yürütmeyi durdurmaz); öneri/iptal/kaldırılanlar sayılmaz.
 */
class SuspensionCalendar
{
    private static ?bool $ready = null;

    /**
     * @param  iterable<int>  $studentIds
     * @return array<int, array{sanction_id:int, sanction_no:string, starts_on:string, ends_on:string, label:string}>
     */
    public function onDate(iterable $studentIds, string $date): array
    {
        $ids = [];
        foreach ($studentIds as $id) {
            $ids[] = (int) $id;
        }
        if ($ids === [] || ! (self::$ready ??= Schema::hasTable('discipline_sanctions'))) {
            return [];
        }

        $rows = DB::table('discipline_sanctions as ds')
            ->join('discipline_sanction_types as t', 't.id', '=', 'ds.sanction_type_id')
            ->whereIn('ds.student_id', array_values(array_unique($ids)))
            ->whereNull('ds.deleted_at')->where('t.is_suspension', true)
            ->whereIn('ds.status', ['active', 'appealed'])
            ->where('ds.starts_on', '<=', $date.' 23:59:59')->where('ds.ends_on', '>=', $date)
            ->get(['ds.id', 'ds.student_id', 'ds.sanction_no', 'ds.starts_on', 'ds.ends_on']);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->student_id] = [
                'sanction_id' => (int) $r->id, 'sanction_no' => (string) $r->sanction_no,
                'starts_on' => (string) $r->starts_on, 'ends_on' => (string) $r->ends_on,
                'label' => 'Disiplin: geçici uzaklaştırma ('.$r->sanction_no.')',
            ];
        }

        return $out;
    }
}
