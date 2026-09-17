<?php

namespace App\Http\Controllers\Api\Finance;

use App\Models\Installment;
use App\Services\Finance\ReceivableAging;
use App\Support\Audit;
use App\Support\Sensitive;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Taksitler ve alacaklar. Durum filtresi vade tarihinden hesaplanır (gece işini beklemez):
 * open = ödenmemiş; overdue = ödenmemiş ve vadesi geçmiş; pending/partial = vadesi gelmemiş.
 */
class InstallmentController extends FinanceController
{
    public const EFFECTIVE = ['pending' => 'Bekliyor', 'partial' => 'Kısmi', 'overdue' => 'Gecikti', 'paid' => 'Ödendi', 'cancelled' => 'İptal'];

    public function index(Request $request): JsonResponse
    {
        $today = CarbonImmutable::today()->toDateString();
        $query = $this->filtered($request, applyStatus: true);

        $totals = (clone $query)->reorder()->select([])->selectRaw('COUNT(*) AS count, COALESCE(SUM(i.amount), 0) AS amount, COALESCE(SUM(i.paid_amount), 0) AS paid,
            COALESCE(SUM(CASE WHEN i.status IN (\'pending\',\'partial\',\'overdue\') THEN i.amount - i.paid_amount ELSE 0 END), 0) AS remaining')->first();

        $counts = $this->filtered($request, applyStatus: false)->reorder()->select([])->selectRaw("
            SUM(i.status IN ('pending','partial','overdue')) AS open,
            SUM(i.status IN ('pending','partial','overdue') AND i.due_date < ?) AS overdue,
            SUM(i.status IN ('pending','partial','overdue') AND i.due_date >= ? AND i.paid_amount = 0) AS pending,
            SUM(i.status IN ('pending','partial','overdue') AND i.due_date >= ? AND i.paid_amount > 0) AS partial,
            SUM(i.status = 'paid') AS paid, COUNT(*) AS `all`", [$today, $today, $today])->first();

        $sort = ['due_date' => 'i.due_date', 'amount' => 'i.amount', 'remaining' => DB::raw('(i.amount - i.paid_amount)'), 'student' => 's.full_name'];
        $key = ltrim((string) $request->query('sort', 'due_date'), '-');
        $dir = str_starts_with((string) $request->query('sort', 'due_date'), '-') ? 'desc' : 'asc';
        $query->orderBy($sort[$key] ?? 'i.due_date', $dir)->orderBy('i.id');

        $page = $query->paginate($this->perPage($request, 50));

        return $this->paginated($page, fn ($r) => $this->row($r), [
            'totals' => ['count' => (int) $totals->count, 'amount' => $this->dec($totals->amount), 'paid' => $this->dec($totals->paid), 'remaining' => $this->dec($totals->remaining)],
            'status_counts' => collect((array) $counts)->map(fn ($v) => (int) $v),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request, applyStatus: true);
        Audit::log('installment.exported', 'taksit ve alacak listesini Excel olarak dışa aktardı.');

        return $this->xlsx('taksitler-'.now()->format('Y-m-d').'.xlsx',
            ['Öğrenci No', 'Öğrenci', 'Veli', 'Kayıt No', 'Program', 'Taksit', 'Vade', 'Tutar', 'Ödenen', 'Kalan', 'Durum', 'Gecikme (gün)'],
            function (callable $add) use ($query) {
                $query->chunkById(1000, function ($chunk) use ($add) {
                    foreach ($chunk as $r) {
                        $row = $this->row($r, true);
                        $add([$r->student_no, $r->student, $r->guardian ?? '', $r->enrollment_no, $r->program ?? '', $r->sequence, CarbonImmutable::parse($r->due_date)->format('d.m.Y'),
                            $this->cell($r->amount), $this->cell($r->paid_amount), $this->cell($row['remaining']), self::EFFECTIVE[$row['effective_status']] ?? $row['effective_status'], $row['days_overdue']]);
                    }
                }, 'i.id', 'id');
            });
    }

    /** Yaşlandırma kovaları (tüm açık alacak). */
    public function aging(Request $request): JsonResponse
    {
        $rows = $this->filtered($request, applyStatus: false)->whereIn('i.status', ['pending', 'partial', 'overdue'])
            ->select(['i.due_date', DB::raw('(i.amount - i.paid_amount) AS remaining')])->get();

        $summary = ReceivableAging::summarize($rows);

        return response()->json(['data' => [
            'buckets' => array_values($summary['buckets']),
            'total' => $summary['total'],
            'overdue' => $summary['overdue'],
        ]]);
    }

    /** Öğrenci (ve veli) bazında açık alacak; yaşlandırma kovaları kolon olarak. */
    public function byStudent(Request $request): JsonResponse
    {
        $today = CarbonImmutable::today()->toDateString();
        $sensitive = $request->user()->can('students.view_sensitive');
        $query = $this->filtered($request, applyStatus: false)->whereIn('i.status', ['pending', 'partial', 'overdue'])
            ->groupBy('i.student_id', 's.full_name', 's.student_no')
            ->select([
                'i.student_id', 's.full_name as student', 's.student_no',
                DB::raw('SUM(i.amount - i.paid_amount) AS remaining'),
                DB::raw('COUNT(*) AS open_count'),
                DB::raw('MIN(i.due_date) AS oldest_due'),
                DB::raw("SUM(CASE WHEN i.due_date < '{$today}' THEN i.amount - i.paid_amount ELSE 0 END) AS overdue"),
                DB::raw("SUM(i.due_date < '{$today}') AS overdue_count"),
                ...collect(ReceivableAging::BUCKETS)->keys()->map(fn ($k) => DB::raw(ReceivableAging::sqlSum($k, 'i.due_date', 'i.amount - i.paid_amount', $today)." AS {$k}"))->all(),
            ]);

        $sort = ['remaining' => 'remaining', 'overdue' => 'overdue', 'oldest_due' => 'oldest_due', 'student' => 's.full_name'];
        $key = ltrim((string) $request->query('sort', '-overdue'), '-');
        $dir = str_starts_with((string) $request->query('sort', '-overdue'), '-') ? 'desc' : 'asc';
        if ($request->boolean('overdue_only')) {
            $query->having('overdue', '>', 0);
        }
        $query->orderBy($sort[$key] ?? 'overdue', $dir)->orderBy('s.full_name');

        $page = $query->paginate($this->perPage($request, 25));
        $ids = collect($page->items())->pluck('student_id');
        $guardians = DB::table('guardian_student as gs')->join('guardians as g', 'g.id', '=', 'gs.guardian_id')
            ->whereIn('gs.student_id', $ids)->whereNull('g.deleted_at')
            ->orderByDesc('gs.is_financially_responsible')->orderByDesc('gs.is_primary')
            ->get(['gs.student_id', 'g.id', 'g.first_name', 'g.last_name', 'g.phone'])->groupBy('student_id');

        return $this->paginated($page, function ($r) use ($guardians, $sensitive, $today) {
            $g = $guardians[$r->student_id][0] ?? null;

            return [
                'student_id' => $r->student_id, 'student' => $r->student, 'student_no' => $r->student_no,
                'guardian' => $g ? ['id' => $g->id, 'name' => trim($g->first_name.' '.$g->last_name), 'phone' => $sensitive ? $g->phone : Sensitive::maskPhone($g->phone)] : null,
                'remaining' => $this->dec($r->remaining), 'overdue' => $this->dec($r->overdue), 'open_count' => (int) $r->open_count, 'overdue_count' => (int) $r->overdue_count,
                'oldest_due' => $r->oldest_due, 'days_overdue' => max(0, ReceivableAging::daysOverdue($r->oldest_due, CarbonImmutable::parse($today))),
                'buckets' => collect(ReceivableAging::BUCKETS)->keys()->mapWithKeys(fn ($k) => [$k => $this->dec($r->{$k})]),
            ];
        });
    }

    private function filtered(Request $request, bool $applyStatus): Builder
    {
        $branchId = $this->branchId();
        $today = CarbonImmutable::today()->toDateString();

        $query = DB::table('installments as i')
            ->join('students as s', 's.id', '=', 'i.student_id')
            ->join('enrollments as e', 'e.id', '=', 'i.enrollment_id')
            ->leftJoin('programs as p', 'p.id', '=', 'e.program_id')
            ->where('i.branch_id', $branchId)
            ->select(['i.id', 'i.student_id', 'i.enrollment_id', 'i.sequence', 'i.due_date', 'i.amount', 'i.paid_amount', 'i.status', 'i.paid_at',
                's.full_name as student', 's.student_no', 'e.enrollment_no', 'p.name as program',
                DB::raw("(SELECT CONCAT(g.first_name, ' ', g.last_name) FROM guardian_student gs JOIN guardians g ON g.id = gs.guardian_id WHERE gs.student_id = i.student_id AND g.deleted_at IS NULL ORDER BY gs.is_financially_responsible DESC, gs.is_primary DESC LIMIT 1) AS guardian")]);

        if ($applyStatus) {
            $status = (string) $request->query('status', 'open');
            match ($status) {
                'overdue' => $query->whereIn('i.status', ['pending', 'partial', 'overdue'])->where('i.due_date', '<', $today),
                'pending' => $query->whereIn('i.status', ['pending', 'partial', 'overdue'])->where('i.due_date', '>=', $today)->where('i.paid_amount', 0),
                'partial' => $query->whereIn('i.status', ['pending', 'partial', 'overdue'])->where('i.due_date', '>=', $today)->where('i.paid_amount', '>', 0),
                'paid' => $query->where('i.status', 'paid'),
                'cancelled' => $query->where('i.status', 'cancelled'),
                'all' => null,
                default => $query->whereIn('i.status', ['pending', 'partial', 'overdue']),
            };
        }

        if ($q = trim((string) $request->query('q'))) {
            $like = '%'.$q.'%';
            $query->where(function (Builder $w) use ($like, $q) {
                $w->where('s.full_name', 'like', $like)->orWhere('s.student_no', $q)->orWhere('e.enrollment_no', $q)
                    ->orWhereExists(fn ($x) => $x->from('guardian_student as gs2')->join('guardians as g2', 'g2.id', '=', 'gs2.guardian_id')
                        ->whereColumn('gs2.student_id', 'i.student_id')->whereRaw("CONCAT(g2.first_name, ' ', g2.last_name) LIKE ?", [$like]));
            });
        }
        if ($from = $request->date('due_from')) {
            $query->where('i.due_date', '>=', $from->toDateString());
        }
        if ($to = $request->date('due_to')) {
            $query->where('i.due_date', '<=', $to->toDateString());
        }
        foreach (['student_id' => 'i.student_id', 'enrollment_id' => 'i.enrollment_id', 'program_id' => 'e.program_id', 'term_id' => 'e.academic_term_id'] as $param => $column) {
            if ($v = $request->integer($param)) {
                $query->where($column, $v);
            }
        }
        if (($bucket = $request->query('bucket')) && isset(ReceivableAging::BUCKETS[$bucket])) {
            $query->whereIn('i.status', ['pending', 'partial', 'overdue'])->whereRaw(ReceivableAging::sqlCondition($bucket, 'i.due_date', $today));
        }
        $query->whereNull('s.deleted_at');

        return $query;
    }

    private function row(object $r, bool $export = false): array
    {
        $today = CarbonImmutable::today();
        $remaining = bcsub((string) $r->amount, (string) $r->paid_amount, 2);
        $open = in_array($r->status, ['pending', 'partial', 'overdue'], true);
        $days = ReceivableAging::daysOverdue($r->due_date, $today);
        $effective = ! $open ? $r->status : ($days > 0 ? 'overdue' : (bccomp((string) $r->paid_amount, '0', 2) > 0 ? 'partial' : 'pending'));

        return [
            'id' => $r->id, 'student_id' => $r->student_id, 'student' => $r->student, 'student_no' => $r->student_no, 'guardian' => $r->guardian,
            'enrollment_id' => $r->enrollment_id, 'enrollment_no' => $r->enrollment_no, 'program' => $r->program,
            'sequence' => (int) $r->sequence, 'due_date' => $r->due_date, 'amount' => $this->dec($r->amount), 'paid_amount' => $this->dec($r->paid_amount),
            'remaining' => $open ? $remaining : '0.00', 'status' => $r->status, 'effective_status' => $effective,
            'days_overdue' => $open ? max(0, $days) : 0, 'bucket' => $open ? ReceivableAging::bucketFor($days) : null, 'paid_at' => $r->paid_at,
        ];
    }

    private function dec(mixed $v): string
    {
        return bcadd((string) ($v ?? '0'), '0', 2);
    }
}
