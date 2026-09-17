<?php

namespace App\Services\Finance;

use App\Models\Guardian;
use App\Models\Student;
use App\Services\Accounting\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cari hesap ekstresi (öğrenci ya da velinin tüm çocukları).
 *  Borç  = ödeme planı taksitleri (vade tarihinde; iptal taksitler hariç) + iadeler
 *  Alacak = geçerli tahsilatlar (iptal edilenler hariç)
 *  Bakiye (borç − alacak) = açık taksit kalanı − dağıtılmamış avans. Vadesi gelmemiş taksitler dahildir;
 *  "vadesi geçen" ayrıca gösterilir.
 */
class StatementService
{
    /**
     * @param list<int> $studentIds
     * @return array{rows: list<array>, opening: string, totals: array{debit:string, credit:string, balance:string, overdue:string, not_due:string, credit_balance:string}, students: list<array>}
     */
    public function build(array $studentIds, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $to ??= CarbonImmutable::today()->addYears(2);
        $students = DB::table('students')->whereIn('id', $studentIds)->get(['id', 'full_name', 'student_no'])->keyBy('id');
        $multi = count($studentIds) > 1;
        $moves = [];

        $inst = DB::table('installments as i')->join('enrollments as e', 'e.id', '=', 'i.enrollment_id')->leftJoin('programs as p', 'p.id', '=', 'e.program_id')
            ->whereIn('i.student_id', $studentIds)->where('i.status', '!=', 'cancelled')
            ->get(['i.id', 'i.student_id', 'i.sequence', 'i.due_date', 'i.amount', 'i.paid_amount', 'e.enrollment_no', 'p.name as program']);
        foreach ($inst as $r) {
            $moves[] = ['date' => substr((string) $r->due_date, 0, 10), 'order' => 1, 'student_id' => $r->student_id, 'type' => 'installment', 'ref' => $r->enrollment_no,
                'description' => "{$r->sequence}. taksit".($r->program ? " — {$r->program}" : ''), 'debit' => Dec::round(Dec::norm($r->amount)), 'credit' => '0.00'];
        }
        $pays = DB::table('payments')->whereIn('student_id', $studentIds)->whereNull('voided_at')->get(['id', 'student_id', 'receipt_no', 'paid_at', 'amount', 'method']);
        foreach ($pays as $r) {
            $moves[] = ['date' => substr((string) $r->paid_at, 0, 10), 'order' => 2, 'student_id' => $r->student_id, 'type' => 'payment', 'ref' => $r->receipt_no,
                'description' => 'Tahsilat — '.(\App\Models\Payment::METHODS[$r->method] ?? $r->method), 'debit' => '0.00', 'credit' => Dec::round(Dec::norm($r->amount)), 'id' => $r->id];
        }
        $refunds = DB::table('refunds')->whereIn('student_id', $studentIds)->whereNull('voided_at')->get(['id', 'student_id', 'refund_no', 'refunded_at', 'amount']);
        foreach ($refunds as $r) {
            $moves[] = ['date' => substr((string) $r->refunded_at, 0, 10), 'order' => 3, 'student_id' => $r->student_id, 'type' => 'refund', 'ref' => $r->refund_no,
                'description' => 'İade', 'debit' => Dec::round(Dec::norm($r->amount)), 'credit' => '0.00'];
        }
        usort($moves, fn ($a, $b) => [$a['date'], $a['order'], $a['ref']] <=> [$b['date'], $b['order'], $b['ref']]);

        $opening = '0.00';
        $balance = '0.00';
        $rows = [];
        $debit = '0.00';
        $credit = '0.00';
        foreach ($moves as $m) {
            $delta = bcsub($m['debit'], $m['credit'], 2);
            if ($from && $m['date'] < $from->toDateString()) {
                $opening = bcadd($opening, $delta, 2);
                $balance = $opening;
                continue;
            }
            if ($m['date'] > $to->toDateString()) {
                continue;
            }
            $balance = bcadd($balance, $delta, 2);
            $debit = bcadd($debit, $m['debit'], 2);
            $credit = bcadd($credit, $m['credit'], 2);
            $s = $students[$m['student_id']] ?? null;
            $rows[] = $m + ['balance' => $balance, 'student' => $multi ? $s?->full_name : null];
        }

        $today = CarbonImmutable::today()->toDateString();
        $open = DB::table('installments')->whereIn('student_id', $studentIds)->whereIn('status', ['pending', 'partial', 'overdue'])
            ->selectRaw('COALESCE(SUM(CASE WHEN due_date < ? THEN amount - paid_amount ELSE 0 END), 0) AS overdue, COALESCE(SUM(CASE WHEN due_date >= ? THEN amount - paid_amount ELSE 0 END), 0) AS not_due', [$today, $today])->first();
        $creditBalance = array_reduce($studentIds, fn ($s, $id) => bcadd($s, StudentCredit::forStudent((int) $id), 2), '0.00');

        return [
            'rows' => $rows,
            'opening' => $opening,
            'totals' => [
                'debit' => $debit, 'credit' => $credit, 'balance' => $balance,
                'overdue' => Dec::round(Dec::norm($open->overdue)), 'not_due' => Dec::round(Dec::norm($open->not_due)), 'credit_balance' => $creditBalance,
            ],
            'students' => $students->values()->map(fn ($s) => ['id' => $s->id, 'full_name' => $s->full_name, 'student_no' => $s->student_no])->all(),
        ];
    }

    /** @return list<int> velinin bağlı (silinmemiş) çocukları */
    public function guardianStudentIds(Guardian $guardian): array
    {
        return DB::table('guardian_student as gs')->join('students as s', 's.id', '=', 'gs.student_id')
            ->where('gs.guardian_id', $guardian->id)->whereNull('s.deleted_at')->pluck('s.id')->map(fn ($v) => (int) $v)->all();
    }

    public function holderForStudent(Student $student): array
    {
        $g = $student->guardians()->orderByDesc('guardian_student.is_financially_responsible')->orderByDesc('guardian_student.is_primary')->first();

        return ['name' => $student->full_name, 'sub' => 'Öğrenci no '.$student->student_no.($g ? ' · Veli: '.trim($g->first_name.' '.$g->last_name) : ''), 'address' => $student->address ?? $g?->address];
    }
}
