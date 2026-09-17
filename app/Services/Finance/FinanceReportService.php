<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Finans raporları ve komuta merkezi göstergeleri. Tutarlar SQL toplamından string alınır, bcmath ile birleştirilir.
 * İptal edilmiş tahsilat ve gelir/gider kayıtları hiçbir toplamda yer almaz.
 */
class FinanceReportService
{
    public const GROUPS = ['day' => 'Günlük', 'week' => 'Haftalık', 'month' => 'Aylık'];

    public function overview(int $branchId): array
    {
        $now = CarbonImmutable::now();
        $today = $now->toDateString();
        $monthStart = $now->startOfMonth();

        $collectedToday = $this->sumPayments($branchId, $now->startOfDay(), $now->endOfDay());
        $collectedMonth = $this->sumPayments($branchId, $monthStart, $now->endOfDay());
        $entries = $this->sumEntries($branchId, $monthStart->toDateString(), $today);
        $incomeMonth = bcadd($collectedMonth, $entries['income'], 2);

        $inst = DB::table('installments')->where('branch_id', $branchId)->whereIn('status', ['pending', 'partial', 'overdue'])
            ->selectRaw("
                COALESCE(SUM(amount - paid_amount), 0) AS receivable,
                COALESCE(SUM(CASE WHEN due_date < ? THEN amount - paid_amount ELSE 0 END), 0) AS overdue_amount,
                COALESCE(SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END), 0) AS overdue_count,
                COUNT(DISTINCT CASE WHEN due_date < ? THEN student_id END) AS overdue_students,
                COALESCE(SUM(CASE WHEN due_date = ? THEN amount - paid_amount ELSE 0 END), 0) AS due_today,
                COALESCE(SUM(CASE WHEN due_date BETWEEN ? AND ? THEN amount - paid_amount ELSE 0 END), 0) AS next7,
                COALESCE(SUM(CASE WHEN due_date BETWEEN ? AND ? THEN amount - paid_amount ELSE 0 END), 0) AS next30
            ", [$today, $today, $today, $today, $today, $now->addDays(7)->toDateString(), $today, $now->addDays(30)->toDateString()])
            ->first();

        $rate = $this->collectionRate($branchId, $monthStart->toDateString(), $monthStart->endOfMonth()->toDateString());

        return [
            'generated_at' => $now->toAtomString(),
            'kpis' => [
                'collected_today' => $collectedToday,
                'collected_month' => $collectedMonth,
                'receivable' => $this->dec($inst->receivable),
                'overdue_amount' => $this->dec($inst->overdue_amount),
                'overdue_count' => (int) $inst->overdue_count,
                'overdue_students' => (int) $inst->overdue_students,
                'due_today' => $this->dec($inst->due_today),
                'due_next_7' => $this->dec($inst->next7),
                'due_next_30' => $this->dec($inst->next30),
                'other_income_month' => $entries['income'],
                'income_month' => $incomeMonth,
                'expense_month' => $entries['expense'],
                'net_month' => bcsub($incomeMonth, $entries['expense'], 2),
                'collection_rate_month' => $rate,
            ],
            'chart' => $this->series($branchId, $now->subMonths(11)->startOfMonth(), $now->endOfMonth(), 'month'),
            'upcoming' => $this->installmentRows($branchId)->whereBetween('i.due_date', [$today, $now->addDays(30)->toDateString()])
                ->orderBy('i.due_date')->limit(40)->get(),
            'overdue' => $this->installmentRows($branchId)->where('i.due_date', '<', $today)
                ->orderBy('i.due_date')->limit(12)->get(),
            'recent_payments' => DB::table('payments as p')->join('students as s', 's.id', '=', 'p.student_id')
                ->leftJoin('finance_accounts as a', 'a.id', '=', 'p.finance_account_id')
                ->where('p.branch_id', $branchId)->orderByDesc('p.paid_at')->orderByDesc('p.id')->limit(10)
                ->get(['p.id', 'p.receipt_no', 'p.amount', 'p.method', 'p.paid_at', 'p.voided_at', 'p.student_id', 's.full_name as student', 'a.name as account']),
            'accounts' => DB::table('finance_accounts')->where('branch_id', $branchId)->whereNull('deleted_at')->where('is_active', true)
                ->orderByRaw("FIELD(kind, 'cash', 'pos', 'bank')")->get(['id', 'kind', 'name', 'balance']),
            'low_stock' => DB::table('products')->where('branch_id', $branchId)->whereNull('deleted_at')->where('is_active', true)
                ->where('min_stock', '>', 0)->whereColumn('stock', '<=', 'min_stock')->count(),
        ];
    }

    /**
     * Dönem raporu.
     *
     * @return array<string, mixed>
     */
    public function report(int $branchId, CarbonImmutable $from, CarbonImmutable $to, string $group): array
    {
        if ($to->lt($from)) {
            throw new BusinessRuleException('Bitiş tarihi başlangıçtan önce olamaz.', 'invalid_range');
        }
        if (! isset(self::GROUPS[$group])) {
            $group = 'day';
        }
        if ($group === 'day' && $from->diffInDays($to) > 400) {
            throw new BusinessRuleException('Günlük gruplama en fazla 400 gün için yapılabilir; haftalık ya da aylık seçin.', 'range_too_long');
        }
        if ($from->diffInDays($to) > 3700) {
            throw new BusinessRuleException('Rapor aralığı en fazla 10 yıl olabilir.', 'range_too_long');
        }

        $rows = $this->series($branchId, $from->startOfDay(), $to->endOfDay(), $group);
        $totals = ['collections' => '0.00', 'other_income' => '0.00', 'income' => '0.00', 'expense' => '0.00', 'net' => '0.00', 'due' => '0.00', 'paid_on_due' => '0.00', 'payment_count' => 0];
        foreach ($rows as $r) {
            foreach (['collections', 'other_income', 'income', 'expense', 'net', 'due', 'paid_on_due'] as $k) {
                $totals[$k] = bcadd($totals[$k], $r[$k], 2);
            }
            $totals['payment_count'] += $r['payment_count'];
        }
        $totals['collection_rate'] = $this->rate($totals['paid_on_due'], $totals['due']);

        $fromDt = $from->startOfDay();
        $toDt = $to->endOfDay();

        $byMethod = DB::table('payments')->where('branch_id', $branchId)->whereNull('voided_at')->whereBetween('paid_at', [$fromDt, $toDt])
            ->groupBy('method')->selectRaw('method, COUNT(*) AS count, SUM(amount) AS amount')->orderByDesc('amount')->get()
            ->map(fn ($r) => ['method' => $r->method, 'label' => Payment::METHODS[$r->method] ?? $r->method, 'count' => (int) $r->count, 'amount' => $this->dec($r->amount)]);

        $byCategory = DB::table('finance_entries as e')->join('finance_categories as c', 'c.id', '=', 'e.finance_category_id')
            ->where('e.branch_id', $branchId)->whereNull('e.voided_at')->whereBetween('e.entry_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('e.direction', 'c.id', 'c.name')->selectRaw('e.direction, c.id, c.name, COUNT(*) AS count, SUM(e.amount) AS amount')->orderByDesc('amount')->get()
            ->map(fn ($r) => ['direction' => $r->direction, 'category_id' => $r->id, 'name' => $r->name, 'count' => (int) $r->count, 'amount' => $this->dec($r->amount)]);

        $open = DB::table('installments')->where('branch_id', $branchId)->whereIn('status', ['pending', 'partial', 'overdue'])
            ->get(['due_date', DB::raw('amount - paid_amount AS remaining')]);
        $aging = ReceivableAging::summarize($open);
        $today = CarbonImmutable::today();
        $expected = DB::table('installments')->where('branch_id', $branchId)->whereIn('status', ['pending', 'partial', 'overdue'])
            ->selectRaw('COALESCE(SUM(CASE WHEN due_date BETWEEN ? AND ? THEN amount - paid_amount ELSE 0 END), 0) AS next7,
                COALESCE(SUM(CASE WHEN due_date BETWEEN ? AND ? THEN amount - paid_amount ELSE 0 END), 0) AS next30',
                [$today->toDateString(), $today->addDays(7)->toDateString(), $today->toDateString(), $today->addDays(30)->toDateString()])->first();

        $voided = DB::table('payments')->where('branch_id', $branchId)->whereNotNull('voided_at')->whereBetween('paid_at', [$fromDt, $toDt])
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')->first();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'group' => $group,
            'rows' => $rows,
            'totals' => $totals,
            'by_method' => $byMethod->values(),
            'income_by_category' => collect([['direction' => 'income', 'category_id' => null, 'name' => 'Öğrenci tahsilatları', 'count' => $totals['payment_count'], 'amount' => $totals['collections']]])
                ->concat($byCategory->where('direction', 'income'))->values(),
            'expense_by_category' => $byCategory->where('direction', 'expense')->values(),
            'voided_payments' => ['count' => (int) $voided->count, 'amount' => $this->dec($voided->amount)],
            'receivables' => [
                'total' => $aging['total'],
                'overdue' => $aging['overdue'],
                'aging' => array_values($aging['buckets']),
                'expected_next_7' => $this->dec($expected->next7),
                'expected_next_30' => $this->dec($expected->next30),
            ],
        ];
    }

    /**
     * Zaman serisi: tahsilat, diğer gelir, gelir, gider, net, vadesi gelen ve vadesi gelen taksitlerin ödenen kısmı.
     *
     * @return list<array<string, mixed>>
     */
    public function series(int $branchId, CarbonImmutable $from, CarbonImmutable $to, string $group): array
    {
        $buckets = [];
        $cursor = match ($group) {
            'month' => $from->startOfMonth(),
            'week' => $from->startOfWeek(),
            default => $from->startOfDay(),
        };
        while ($cursor->lte($to)) {
            $key = $this->bucketKey($cursor, $group);
            $buckets[$key] = [
                'period' => $key, 'start' => $cursor->toDateString(), 'label' => $this->bucketLabel($cursor, $group),
                'collections' => '0.00', 'other_income' => '0.00', 'income' => '0.00', 'expense' => '0.00', 'net' => '0.00',
                'due' => '0.00', 'paid_on_due' => '0.00', 'collection_rate' => null, 'payment_count' => 0,
            ];
            $cursor = match ($group) {
                'month' => $cursor->addMonthNoOverflow(),
                'week' => $cursor->addWeek(),
                default => $cursor->addDay(),
            };
        }

        $payments = DB::table('payments')->where('branch_id', $branchId)->whereNull('voided_at')->whereBetween('paid_at', [$from, $to])
            ->selectRaw('DATE(paid_at) AS d, COUNT(*) AS c, SUM(amount) AS total')->groupBy('d')->get();
        foreach ($payments as $p) {
            $k = $this->bucketKey(CarbonImmutable::parse($p->d), $group);
            if (isset($buckets[$k])) {
                $buckets[$k]['collections'] = bcadd($buckets[$k]['collections'], $this->dec($p->total), 2);
                $buckets[$k]['payment_count'] += (int) $p->c;
            }
        }

        $entries = DB::table('finance_entries')->where('branch_id', $branchId)->whereNull('voided_at')
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('entry_date AS d, direction, SUM(amount) AS total')->groupBy('d', 'direction')->get();
        foreach ($entries as $e) {
            $k = $this->bucketKey(CarbonImmutable::parse($e->d), $group);
            if (isset($buckets[$k])) {
                $field = $e->direction === 'income' ? 'other_income' : 'expense';
                $buckets[$k][$field] = bcadd($buckets[$k][$field], $this->dec($e->total), 2);
            }
        }

        $due = DB::table('installments')->where('branch_id', $branchId)->where('status', '!=', 'cancelled')
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('due_date AS d, SUM(amount) AS due, SUM(paid_amount) AS paid')->groupBy('d')->get();
        foreach ($due as $r) {
            $k = $this->bucketKey(CarbonImmutable::parse($r->d), $group);
            if (isset($buckets[$k])) {
                $buckets[$k]['due'] = bcadd($buckets[$k]['due'], $this->dec($r->due), 2);
                $buckets[$k]['paid_on_due'] = bcadd($buckets[$k]['paid_on_due'], $this->dec($r->paid), 2);
            }
        }

        foreach ($buckets as &$b) {
            $b['income'] = bcadd($b['collections'], $b['other_income'], 2);
            $b['net'] = bcsub($b['income'], $b['expense'], 2);
            $b['collection_rate'] = $this->rate($b['paid_on_due'], $b['due']);
        }

        return array_values($buckets);
    }

    public function collectionRate(int $branchId, string $from, string $to): ?float
    {
        $r = DB::table('installments')->where('branch_id', $branchId)->where('status', '!=', 'cancelled')
            ->whereBetween('due_date', [$from, $to])->selectRaw('COALESCE(SUM(amount), 0) AS due, COALESCE(SUM(paid_amount), 0) AS paid')->first();

        return $this->rate($this->dec($r->paid), $this->dec($r->due));
    }

    /** Yüzde, gösterim için 1 ondalık (tutar değil). */
    public function rate(string $paid, string $due): ?float
    {
        if (bccomp($due, '0', 2) <= 0) {
            return null;
        }

        return (float) bcdiv(bcmul($paid, '1000', 2), $due, 0) / 10;
    }

    private function installmentRows(int $branchId)
    {
        return DB::table('installments as i')->join('students as s', 's.id', '=', 'i.student_id')
            ->join('enrollments as e', 'e.id', '=', 'i.enrollment_id')
            ->where('i.branch_id', $branchId)->whereIn('i.status', ['pending', 'partial', 'overdue'])->whereNull('s.deleted_at')
            ->select(['i.id', 'i.student_id', 'i.enrollment_id', 'i.sequence', 'i.due_date', 'i.amount', 'i.paid_amount', 'i.status',
                DB::raw('(i.amount - i.paid_amount) AS remaining'), 's.full_name as student', 's.student_no', 'e.enrollment_no']);
    }

    private function sumPayments(int $branchId, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $this->dec(DB::table('payments')->where('branch_id', $branchId)->whereNull('voided_at')->whereBetween('paid_at', [$from, $to])->sum('amount'));
    }

    /** @return array{income:string, expense:string} */
    private function sumEntries(int $branchId, string $from, string $to): array
    {
        $rows = DB::table('finance_entries')->where('branch_id', $branchId)->whereNull('voided_at')->whereBetween('entry_date', [$from, $to])
            ->groupBy('direction')->selectRaw('direction, SUM(amount) AS total')->pluck('total', 'direction');

        return ['income' => $this->dec($rows['income'] ?? 0), 'expense' => $this->dec($rows['expense'] ?? 0)];
    }

    private function bucketKey(CarbonImmutable $d, string $group): string
    {
        return match ($group) {
            'month' => $d->format('Y-m'),
            'week' => $d->startOfWeek()->toDateString(),
            default => $d->toDateString(),
        };
    }

    private function bucketLabel(CarbonImmutable $d, string $group): string
    {
        return match ($group) {
            'month' => $d->locale('tr')->translatedFormat('F Y'),
            'week' => $d->startOfWeek()->format('d.m').' – '.$d->endOfWeek()->format('d.m.Y'),
            default => $d->format('d.m.Y'),
        };
    }

    private function dec(mixed $v): string
    {
        return bcadd((string) ($v ?? '0'), '0', 2);
    }
}
