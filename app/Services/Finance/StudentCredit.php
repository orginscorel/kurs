<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

/** Öğrenci avansı: geçerli tahsilatların taksitlere dağıtılmamış ve iade edilmemiş toplamı. */
final class StudentCredit
{
    public static function forStudent(int $studentId): string
    {
        return array_reduce(self::byPayment($studentId), fn ($sum, $c) => bcadd($sum, $c, 2), '0.00');
    }

    /** @return array<int, string> ödeme id → avans (yalnız pozitifler) */
    public static function byPayment(int $studentId): array
    {
        return self::query()->where('p.student_id', $studentId)
            ->selectRaw('p.id, p.amount - COALESCE(a.allocated, 0) - COALESCE(r.refunded, 0) AS c')->get()
            ->filter(fn ($r) => bccomp((string) $r->c, '0', 2) > 0)
            ->mapWithKeys(fn ($r) => [(int) $r->id => bcadd((string) $r->c, '0', 2)])->all();
    }

    private static function query()
    {
        return DB::table('payments as p')
            ->leftJoinSub(DB::table('payment_allocations')->groupBy('payment_id')->selectRaw('payment_id, SUM(amount) AS allocated'), 'a', 'a.payment_id', '=', 'p.id')
            ->leftJoinSub(DB::table('refunds')->whereNull('voided_at')->groupBy('payment_id')->selectRaw('payment_id, SUM(from_credit) AS refunded'), 'r', 'r.payment_id', '=', 'p.id')
            ->whereNull('p.voided_at');
    }
}
