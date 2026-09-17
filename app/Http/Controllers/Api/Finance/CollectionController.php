<?php

namespace App\Http\Controllers\Api\Finance;

use App\Models\CollectionNote;
use App\Models\Student;
use App\Services\Finance\CollectionService;
use App\Services\Finance\ReceivableAging;
use App\Services\Finance\StudentCredit;
use App\Support\Sensitive;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Gecikme takibi: öğrenci bazında gecikmiş alacak + son söz/not + sorumlu kişi. */
class CollectionController extends FinanceController
{
    public function __construct(private readonly CollectionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $b = $this->branchId();
        $today = CarbonImmutable::today()->toDateString();
        $sensitive = $request->user()->can('students.view_sensitive');

        $q = DB::table('installments as i')->join('students as s', 's.id', '=', 'i.student_id')
            ->where('i.branch_id', $b)->whereIn('i.status', ['pending', 'partial', 'overdue'])->whereNull('s.deleted_at')->where('i.due_date', '<', $today)
            ->groupBy('i.student_id', 's.full_name', 's.student_no')
            ->select(['i.student_id', 's.full_name', 's.student_no', DB::raw('SUM(i.amount - i.paid_amount) AS overdue'), DB::raw('MIN(i.due_date) AS oldest'), DB::raw('COUNT(*) AS cnt'),
                ...collect(['d0_30', 'd31_60', 'd61_90', 'd90_plus'])->map(fn ($k) => DB::raw(ReceivableAging::sqlSum($k, 'i.due_date', 'i.amount - i.paid_amount', $today)." AS {$k}"))->all()]);
        if ($search = trim((string) $request->query('q'))) {
            $q->where('s.full_name', 'like', "%{$search}%");
        }
        if (($bucket = $request->query('bucket')) && isset(ReceivableAging::BUCKETS[$bucket])) {
            $q->havingRaw(ReceivableAging::sqlSum($bucket, 'i.due_date', 'i.amount - i.paid_amount', $today).' > 0');
        }
        $promiseSub = DB::table('collection_notes')->where('branch_id', $b)->where('kind', 'promise')->where('status', 'open')
            ->groupBy('student_id')->selectRaw('student_id, MIN(promised_date) AS promise_date');
        $q->leftJoinSub($promiseSub, 'pr', 'pr.student_id', '=', 'i.student_id')->addSelect(DB::raw('MAX(pr.promise_date) AS promise_date'));
        // due: sözü gelmiş (bugün ya da geçmiş) · today: söz tarihi bugün · late: söz tarihi geçmiş ve borç hâlâ ödenmemiş
        if ($request->query('promise') === 'due') {
            $q->havingRaw('MAX(pr.promise_date) <= ?', [$today]);
        } elseif ($request->query('promise') === 'today') {
            $q->havingRaw('MAX(pr.promise_date) = ?', [$today]);
        } elseif ($request->query('promise') === 'late') {
            $q->havingRaw('MAX(pr.promise_date) < ?', [$today]);
        } elseif ($request->query('promise') === 'none') {
            $q->havingRaw('MAX(pr.promise_date) IS NULL');
        }
        if ($r = $request->integer('responsible_id')) {
            $q->whereExists(fn ($x) => $x->from('collection_notes as cn')->whereColumn('cn.student_id', 'i.student_id')->where('cn.responsible_user_id', $r)->where('cn.status', 'open'));
        }
        $sort = (string) $request->query('sort', '-overdue');
        $col = ['overdue' => 'overdue', 'oldest' => 'oldest', 'student' => 's.full_name', 'promise' => 'promise_date'][ltrim($sort, '-')] ?? 'overdue';
        $q->orderBy($col, str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('s.full_name');

        $summary = ReceivableAging::summarize(DB::table('installments')->where('branch_id', $b)->whereIn('status', ['pending', 'partial', 'overdue'])->get(['due_date', DB::raw('amount - paid_amount AS remaining')]));
        // Kutular listeyle aynı ölçüyü kullanır: öğrenci sayısı; yalnız vadesi geçmiş ödenmemiş taksiti olan öğrenciler
        // (öğrencinin en erken açık sözü esas alınır — listedeki "Ödeme sözü" sütunu gibi)
        $unpaidStudents = DB::table('installments as ui')->where('ui.branch_id', $b)->whereIn('ui.status', ['pending', 'partial', 'overdue'])
            ->where('ui.due_date', '<', $today)->select('ui.student_id');
        $firstPromise = DB::table('collection_notes')->where('branch_id', $b)->where('kind', 'promise')->where('status', 'open')
            ->whereIn('student_id', $unpaidStudents)->groupBy('student_id')->selectRaw('student_id, MIN(promised_date) AS d');
        $promises = DB::query()->fromSub($firstPromise, 'fp')
            ->selectRaw('SUM(CASE WHEN d < ? THEN 1 ELSE 0 END) AS broken_candidates, SUM(CASE WHEN d = ? THEN 1 ELSE 0 END) AS today, COUNT(*) AS open', [$today, $today])->first();

        $page = $q->paginate($this->perPage($request, 25));
        $ids = collect($page->items())->pluck('student_id');
        $last = CollectionNote::query()->whereIn('student_id', $ids)->with('responsible:id,name')->orderByDesc('id')->get()->groupBy('student_id');
        $guardians = DB::table('guardian_student as gs')->join('guardians as g', 'g.id', '=', 'gs.guardian_id')->whereIn('gs.student_id', $ids)->whereNull('g.deleted_at')
            ->orderByDesc('gs.is_financially_responsible')->orderByDesc('gs.is_primary')->get(['gs.student_id', 'g.id', 'g.first_name', 'g.last_name', 'g.phone'])->groupBy('student_id');

        return $this->paginated($page, function ($r) use ($last, $guardians, $sensitive) {
            $notes = $last[$r->student_id] ?? collect();
            $promise = $notes->first(fn ($n) => $n->kind === 'promise' && $n->status === 'open');
            $g = $guardians[$r->student_id][0] ?? null;

            return [
                'student_id' => $r->student_id, 'student' => $r->full_name, 'student_no' => $r->student_no,
                'overdue' => bcadd((string) $r->overdue, '0', 2), 'count' => (int) $r->cnt, 'oldest' => $r->oldest, 'days' => max(0, ReceivableAging::daysOverdue($r->oldest)),
                'bucket' => ReceivableAging::bucketFor(ReceivableAging::daysOverdue($r->oldest)),
                'buckets' => collect(['d0_30', 'd31_60', 'd61_90', 'd90_plus'])->mapWithKeys(fn ($k) => [$k => bcadd((string) $r->{$k}, '0', 2)]),
                'guardian' => $g ? ['id' => $g->id, 'name' => trim($g->first_name.' '.$g->last_name), 'phone' => $sensitive ? $g->phone : Sensitive::maskPhone($g->phone)] : null,
                'promise' => $promise ? $this->note($promise) : null,
                'last_note' => ($n = $notes->first()) ? $this->note($n) : null,
                'responsible' => $notes->first(fn ($n) => $n->responsible_user_id)?->responsible?->name,
                'credit' => StudentCredit::forStudent((int) $r->student_id),
            ];
        }, [
            'summary' => ['total' => $summary['total'], 'overdue' => $summary['overdue'], 'buckets' => array_values($summary['buckets'])],
            'promises' => ['open' => (int) ($promises->open ?? 0), 'today' => (int) ($promises->today ?? 0), 'late' => (int) ($promises->broken_candidates ?? 0)],
        ]);
    }

    public function notes(Request $request): JsonResponse
    {
        $this->validateTr($request, ['student_id' => ['required', 'integer']]);

        return response()->json(['data' => CollectionNote::query()->where('student_id', $request->integer('student_id'))
            ->with(['responsible:id,name', 'creator:id,name'])->orderByDesc('id')->limit(100)->get()->map(fn ($n) => $this->note($n))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, [
            'student_id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(array_keys(CollectionNote::KINDS))],
            'guardian_id' => ['nullable', 'integer'],
            'promised_date' => ['nullable', 'date_format:Y-m-d'],
            'promised_amount' => ['nullable', 'string', self::MONEY],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);
        $note = $this->service->add($data);

        return response()->json(['message' => CollectionNote::KINDS[$note->kind].' kaydedildi.', 'data' => $this->note($note->load(['responsible', 'creator']))], 201);
    }

    public function update(Request $request, CollectionNote $note): JsonResponse
    {
        $data = $this->validateTr($request, ['status' => ['required', Rule::in(array_keys(CollectionNote::STATUSES))], 'responsible_user_id' => ['nullable', 'integer', 'exists:users,id']]);
        $this->service->setStatus($note, $data['status'], $data['responsible_user_id'] ?? null);

        return $this->ok('Takip kaydı güncellendi.');
    }

    public function reminderPreview(Student $student): JsonResponse
    {
        return response()->json(['data' => $this->service->reminderPreview($student)]);
    }

    public function reminderDrafts(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, ['student_ids' => ['required', 'array', 'min:1', 'max:200'], 'student_ids.*' => ['integer']]);
        $n = $this->service->saveReminderDrafts($data['student_ids']);

        return $this->ok("{$n} hatırlatma taslağı hazırlandı. Mesajlar GÖNDERİLMEDİ; metni kopyalayıp kendiniz iletebilirsiniz.", ['count' => $n]);
    }

    public function staff(): JsonResponse
    {
        return response()->json(['data' => DB::table('users')->where('branch_id', $this->branchId())->where('user_type', 'staff')->where('is_active', true)
            ->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])]);
    }

    private function note(CollectionNote $n): array
    {
        return [
            'id' => $n->id, 'student_id' => $n->student_id, 'kind' => $n->kind, 'kind_label' => CollectionNote::KINDS[$n->kind] ?? $n->kind,
            'status' => $n->status, 'status_label' => CollectionNote::STATUSES[$n->status] ?? $n->status,
            'promised_date' => $n->promised_date?->toDateString(), 'promised_amount' => $n->promised_amount !== null ? (string) $n->promised_amount : null,
            'body' => $n->body, 'channel' => $n->channel, 'responsible' => $n->responsible?->name, 'responsible_user_id' => $n->responsible_user_id,
            'created_by' => $n->relationLoaded('creator') ? $n->creator?->name : null, 'created_at' => $n->created_at?->toAtomString(),
        ];
    }
}
