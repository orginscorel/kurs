<?php

namespace App\Http\Controllers\Api\Discipline;

use App\Http\Controllers\Api\ApiController;
use App\Models\DisciplineBoardItem;
use App\Models\DisciplineBoardMeeting;
use App\Models\DisciplineIncident;
use App\Models\DisciplineSanction;
use App\Services\Discipline\DisciplineBoardService;
use App\Services\Discipline\DisciplinePdf;
use App\Support\Audit;
use App\Support\Discipline\DisciplineCatalog as C;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Disiplin kurulu: toplantı listesi/ayrıntısı, gündem, oylama, karar tutanağı PDF. */
class BoardController extends ApiController
{
    public function __construct(private readonly DisciplineBoardService $board) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(array_keys(C::BOARD_STATUSES))]]);
        $query = DisciplineBoardMeeting::query()
            ->withCount(['items', 'items as pending_count' => fn ($q) => $q->where('result', 'pending')])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByRaw("CASE status WHEN 'planned' THEN 0 WHEN 'held' THEN 1 ELSE 2 END")
            ->orderByDesc('scheduled_at');

        return $this->paginated($query->paginate($this->perPage($request)), fn ($m) => DisciplinePresenter::meeting($m), [
            'awaiting' => DisciplineSanction::query()->where('status', 'proposed')->whereNull('board_meeting_id')->count(),
        ]);
    }

    /** Kurula gidecek olaylar: kurul kararı bekleyen öneriler + gündemi olmayan inceleme olayları. */
    public function candidates(): JsonResponse
    {
        $proposed = DisciplineSanction::query()->where('status', 'proposed')->with(['student:id,full_name,student_no', 'type:id,name,level,code,authority,is_suspension,tone', 'incident:id,incident_no,occurred_at,severity'])
            ->orderBy('id')->get();
        $defenses = DB::table('discipline_defenses')->whereIn('incident_id', $proposed->pluck('incident_id'))->get(['incident_id', 'student_id', 'status'])
            ->keyBy(fn ($d) => $d->incident_id.'-'.$d->student_id);

        return response()->json(['data' => $proposed->map(fn ($s) => [
            'sanction_id' => $s->id, 'sanction_no' => $s->sanction_no, 'incident_id' => $s->incident_id, 'incident_no' => $s->incident?->incident_no,
            'occurred_at' => $s->incident?->occurred_at?->toAtomString(), 'severity' => $s->incident?->severity,
            'student' => $s->student?->only(['id', 'full_name', 'student_no']), 'type' => $s->type?->name, 'days' => $s->days,
            'board_meeting_id' => $s->board_meeting_id,
            'defense_status' => $defenses[$s->incident_id.'-'.$s->student_id]->status ?? null,
        ])->values()]);
    }

    public function show(DisciplineBoardMeeting $meeting): JsonResponse
    {
        $meeting->load(['items.incident:id,incident_no,occurred_at,severity,status,title', 'items.student:id,full_name,student_no', 'items.sanction.type']);
        $incidentIds = $meeting->items->pluck('incident_id')->unique();
        $involved = DB::table('discipline_incident_students as p')->join('students as s', 's.id', '=', 'p.student_id')->leftJoin('discipline_behaviors as b', 'b.id', '=', 'p.behavior_id')
            ->whereIn('p.incident_id', $incidentIds)->where('p.role', 'involved')
            ->get(['p.incident_id', 'p.student_id', 's.full_name', 'b.name as behavior'])->groupBy('incident_id');
        $defenses = DB::table('discipline_defenses')->whereIn('incident_id', $incidentIds)->get(['incident_id', 'student_id', 'status', 'statement'])
            ->keyBy(fn ($d) => $d->incident_id.'-'.$d->student_id);

        return response()->json(['data' => DisciplinePresenter::meeting($meeting) + [
            'items' => $meeting->items->map(fn (DisciplineBoardItem $it) => [
                'id' => $it->id, 'position' => $it->position, 'result' => $it->result,
                'votes_for' => $it->votes_for, 'votes_against' => $it->votes_against, 'votes_abstain' => $it->votes_abstain, 'decision' => $it->decision,
                'incident' => $it->incident ? ['id' => $it->incident->id, 'incident_no' => $it->incident->incident_no, 'occurred_at' => $it->incident->occurred_at->toAtomString(),
                    'severity' => $it->incident->severity, 'severity_label' => C::SEVERITIES[$it->incident->severity] ?? '', 'status' => $it->incident->status, 'title' => $it->incident->title] : null,
                'student' => $it->student?->only(['id', 'full_name', 'student_no']),
                'involved' => ($involved[$it->incident_id] ?? collect())->map(fn ($r) => ['student_id' => (int) $r->student_id, 'full_name' => $r->full_name, 'behavior' => $r->behavior,
                    'defense_status' => $defenses[$r->incident_id.'-'.$r->student_id]->status ?? null])->values(),
                'sanction' => $it->sanction ? DisciplinePresenter::sanction($it->sanction) : null,
                'defense' => $it->student_id && isset($defenses[$it->incident_id.'-'.$it->student_id])
                    ? ['status' => $defenses[$it->incident_id.'-'.$it->student_id]->status, 'statement' => $defenses[$it->incident_id.'-'.$it->student_id]->statement] : null,
            ])->values(),
        ]]);
    }

    private function memberRules(): array
    {
        return [
            'members' => ['nullable', 'array', 'max:15'],
            'members.*.user_id' => ['required', 'integer'],
            'members.*.role' => ['nullable', Rule::in(array_keys(C::BOARD_ROLES))],
            'members.*.present' => ['nullable', 'boolean'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'scheduled_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'incident_ids' => ['nullable', 'array', 'max:40'],
            'incident_ids.*' => ['integer'],
        ] + $this->memberRules(), DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        $meeting = $this->board->create($data, $request->user());

        return response()->json(['message' => 'Kurul toplantısı planlandı.', 'id' => $meeting->id], 201);
    }

    public function update(Request $request, DisciplineBoardMeeting $meeting): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'scheduled_at' => ['sometimes', 'date'],
            'location' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ] + $this->memberRules(), DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        $this->board->update($meeting, $data, $request->user());

        return $this->ok('Toplantı güncellendi.');
    }

    public function status(Request $request, DisciplineBoardMeeting $meeting): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(C::BOARD_STATUSES))]] + $this->memberRules(),
            DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        $this->board->setStatus($meeting, $data['status'], $request->user(), $data['members'] ?? null);

        return $this->ok('Toplantı durumu: '.C::BOARD_STATUSES[$data['status']].'.');
    }

    public function addItem(Request $request, DisciplineBoardMeeting $meeting): JsonResponse
    {
        $data = $request->validate(['incident_id' => ['required', 'integer', Rule::exists('discipline_incidents', 'id')]], DisciplinePresenter::messages(), ['incident_id' => 'Olay']);
        $n = $this->board->addIncident($meeting, (int) $data['incident_id'], $request->user());

        return $this->ok("Gündeme {$n} madde eklendi.");
    }

    public function removeItem(Request $request, DisciplineBoardMeeting $meeting, DisciplineBoardItem $item): JsonResponse
    {
        abort_unless($item->meeting_id === $meeting->id, 404);
        $this->board->removeItem($item, $request->user());

        return $this->ok('Madde gündemden çıkarıldı.');
    }

    public function decide(Request $request, DisciplineBoardMeeting $meeting, DisciplineBoardItem $item): JsonResponse
    {
        abort_unless($item->meeting_id === $meeting->id, 404);
        $data = $request->validate([
            'result' => ['required', Rule::in(['accepted', 'rejected', 'postponed'])],
            'votes_for' => ['required', 'integer', 'min:0', 'max:30'],
            'votes_against' => ['required', 'integer', 'min:0', 'max:30'],
            'votes_abstain' => ['nullable', 'integer', 'min:0', 'max:30'],
            'decision' => ['nullable', 'string', 'max:3000'],
            'sanction_type_id' => ['nullable', 'integer', Rule::exists('discipline_sanction_types', 'id')],
            'student_id' => ['nullable', 'integer'],
            'starts_on' => ['nullable', 'date'],
            'days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'duty_description' => ['nullable', 'string', 'max:300'],
        ], DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        $this->board->decideItem($item, $data, $request->user());

        return $this->ok('Kurul kararı kaydedildi.');
    }

    public function pdf(Request $request, DisciplineBoardMeeting $meeting, DisciplinePdf $pdf): Response
    {
        Audit::log('discipline.board_pdf', "{$meeting->meeting_no} kurul karar tutanağını oluşturdu.", $meeting);

        return $pdf->boardDecision($meeting, $request->boolean('download'));
    }

    /** Toplantıya eklenebilecek olaylar (açık / incelemede olumsuz olaylar). */
    public function incidentSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        $rows = DisciplineIncident::query()->where('kind', 'negative')->whereIn('status', ['open', 'review', 'decided'])
            ->with('participants.student:id,full_name')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('incident_no', 'like', "%{$q}%")
                ->orWhereHas('participants.student', fn ($s) => $s->where('full_name', 'like', "%{$q}%"))))
            ->orderByDesc('occurred_at')->limit(15)->get();

        return response()->json(['data' => $rows->map(fn ($i) => [
            'id' => $i->id, 'incident_no' => $i->incident_no, 'occurred_at' => $i->occurred_at->toAtomString(), 'severity' => $i->severity,
            'status_label' => C::INCIDENT_STATUSES[$i->status], 'students' => $i->participants->where('role', 'involved')->pluck('student.full_name')->implode(', '),
        ])]);
    }
}
