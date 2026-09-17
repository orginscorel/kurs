<?php

namespace App\Http\Controllers\Api\Discipline;

use App\Http\Controllers\Api\ApiController;
use App\Models\ClassGroup;
use App\Models\DisciplineBehavior;
use App\Models\DisciplineBoardMeeting;
use App\Models\DisciplineDefense;
use App\Models\DisciplineIncident;
use App\Models\DisciplineSanction;
use App\Models\DisciplineSanctionType;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Discipline\DisciplineSettings;
use App\Services\Discipline\DisciplineStanding;
use App\Support\Audit;
use App\Support\BranchContext;
use App\Support\Discipline\DisciplineCatalog as C;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Disiplin genel bakış, seçenekler, ayarlar ve öğrenci özeti. */
class DisciplineController extends ApiController
{
    public function __construct(private readonly DisciplineStanding $standing) {}

    public function overview(Request $request): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();
        $today = CarbonImmutable::today();
        $weekStart = $today->startOfWeek();

        $counts = DisciplineIncident::query()->selectRaw("
            SUM(CASE WHEN kind = 'negative' AND occurred_at >= ? THEN 1 ELSE 0 END) AS today,
            SUM(CASE WHEN kind = 'negative' AND occurred_at >= ? THEN 1 ELSE 0 END) AS week,
            SUM(CASE WHEN kind = 'positive' AND occurred_at >= ? THEN 1 ELSE 0 END) AS positives_week,
            SUM(CASE WHEN kind = 'negative' AND status IN ('open','review') THEN 1 ELSE 0 END) AS open,
            SUM(CASE WHEN kind = 'negative' AND status = 'appealed' THEN 1 ELSE 0 END) AS appealed
        ", [$today->toDateTimeString(), $weekStart->toDateTimeString(), $weekStart->toDateTimeString()])->first();

        $suspendedToday = DisciplineSanction::query()->with(['student:id,full_name,student_no', 'type:id,name,is_suspension,code,level,authority,tone'])
            ->whereIn('status', ['active', 'appealed'])->whereHas('type', fn ($q) => $q->where('is_suspension', true))
            ->where('starts_on', '<=', $today->toDateString())->where('ends_on', '>=', $today->toDateString())->get();

        $pendingDefenses = DisciplineDefense::query()->with(['student:id,full_name,student_no', 'incident:id,incident_no'])
            ->where('status', 'requested')->orderBy('due_on')->limit(8)->get();

        $recent = DisciplineIncident::query()->with(['participants.student:id,full_name,student_no', 'participants.behavior:id,name', 'reporter:id,name'])
            ->orderByDesc('occurred_at')->limit(8)->get();

        $weeks = [];
        $from = $weekStart->subWeeks(7);
        $raw = DisciplineIncident::query()->where('occurred_at', '>=', $from)
            ->get(['occurred_at', 'kind'])->groupBy(fn ($i) => $i->occurred_at->startOfWeek()->toDateString());
        for ($w = $from; $w->lte($weekStart); $w = $w->addWeek()) {
            $rows = $raw[$w->toDateString()] ?? collect();
            $weeks[] = ['week' => $w->toDateString(), 'label' => $w->format('d.m'), 'incidents' => $rows->where('kind', 'negative')->count(), 'positives' => $rows->where('kind', 'positive')->count()];
        }

        $topBehaviors = DB::table('discipline_incident_students as p')->join('discipline_incidents as i', 'i.id', '=', 'p.incident_id')
            ->join('discipline_behaviors as b', 'b.id', '=', 'p.behavior_id')
            ->where('i.branch_id', $branchId)->whereNull('i.deleted_at')->where('i.kind', 'negative')->where('p.role', 'involved')
            ->where('i.occurred_at', '>=', $today->subDays(30))
            ->groupBy('b.id', 'b.name')->selectRaw('b.id, b.name, COUNT(*) AS c')->orderByDesc('c')->limit(5)->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'count' => (int) $r->c])->all();

        $meetings = DisciplineBoardMeeting::query()->where('status', 'planned')->withCount(['items', 'items as pending_count' => fn ($q) => $q->where('result', 'pending')])
            ->orderBy('scheduled_at')->limit(3)->get();

        return response()->json(['data' => [
            'term' => DisciplineStanding::termRange($branchId),
            'counts' => [
                'today' => (int) $counts->today, 'week' => (int) $counts->week, 'positives_week' => (int) $counts->positives_week,
                'open' => (int) $counts->open, 'appealed' => (int) $counts->appealed,
                'active_sanctions' => DisciplineSanction::query()->whereIn('status', ['active', 'appealed'])->count(),
                'proposed' => DisciplineSanction::query()->where('status', 'proposed')->count(),
                'pending_defenses' => DisciplineDefense::query()->where('status', 'requested')->count(),
                'overdue_defenses' => DisciplineDefense::query()->where('status', 'requested')->where('due_on', '<', $today->toDateString())->count(),
                'suspended_today' => $suspendedToday->count(),
            ],
            'suspended_today' => $suspendedToday->map(fn ($s) => DisciplinePresenter::sanction($s))->values(),
            'pending_defenses' => $pendingDefenses->map(fn ($d) => DisciplinePresenter::defense($d))->values(),
            'recent' => $recent->map(fn ($i) => DisciplinePresenter::incidentRow($i))->values(),
            'weekly' => $weeks,
            'top_behaviors' => $topBehaviors,
            'meetings' => $meetings->map(fn ($m) => DisciplinePresenter::meeting($m))->values(),
            'attention' => $this->standing->attentionList($branchId, 8, 'watch'),
        ]]);
    }

    public function attention(Request $request): JsonResponse
    {
        $request->validate(['level' => ['nullable', Rule::in(['watch', 'warning'])]]);

        return response()->json(['data' => $this->standing->attentionList(app(BranchContext::class)->require(), 200, $request->query('level', 'watch'))]);
    }

    public function options(Request $request): JsonResponse
    {
        $all = $request->boolean('all') && $request->user()->can('discipline.settings');

        return response()->json([
            'behaviors' => DisciplineBehavior::query()->when(! $all, fn ($q) => $q->where('is_active', true))
                ->orderBy('kind')->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn ($b) => $b->only(['id', 'code', 'name', 'category', 'kind', 'points', 'severity', 'suggested_sanction', 'description', 'is_active', 'sort_order'])
                    + ['category_label' => C::CATEGORIES[$b->category] ?? $b->category]),
            'sanction_types' => DisciplineSanctionType::query()->when(! $all, fn ($q) => $q->where('is_active', true))->orderBy('level')->orderBy('id')->get()
                ->map(fn ($t) => $t->only(['id', 'code', 'name', 'level', 'authority', 'is_suspension', 'has_duty', 'expires_after_days', 'tone', 'description', 'is_active'])),
            'categories' => C::CATEGORIES,
            'positive_categories' => C::POSITIVE_CATEGORIES,
            'severities' => C::SEVERITIES,
            'incident_statuses' => C::INCIDENT_STATUSES,
            'sanction_statuses' => C::SANCTION_STATUSES,
            'defense_statuses' => C::DEFENSE_STATUSES,
            'appeal_statuses' => C::APPEAL_STATUSES,
            'board_statuses' => C::BOARD_STATUSES,
            'board_roles' => C::BOARD_ROLES,
            'roles' => C::ROLES,
            'levels' => C::LEVELS,
            'class_groups' => ClassGroup::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'subjects' => Subject::query()->orderBy('name')->get(['id', 'name']),
            'teachers' => Teacher::query()->where('is_active', true)->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->full_name]),
            'staff' => User::query()->where('is_active', true)->whereIn('user_type', [User::TYPE_STAFF, User::TYPE_TEACHER])
                ->where(fn ($q) => $q->whereNotIn('id', [1, 79])->orWhere('id', $request->user()->id))
                ->orderBy('name')->get(['id', 'name']),
            'settings' => DisciplineSettings::all(),
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => DisciplineSettings::all()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'portal_enabled' => ['sometimes', 'boolean'],
            'portal_defense_requests' => ['sometimes', 'boolean'],
            'portal_defense_submission' => ['sometimes', 'boolean'],
            'teacher_portal_reporting' => ['sometimes', 'boolean'],
            'merit_offsets_penalty' => ['sometimes', 'boolean'],
            'default_defense_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'threshold_watch' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'threshold_warning' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'threshold_critical' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'suspension_attendance_status' => ['sometimes', Rule::in(['excused', 'absent'])],
        ], DisciplinePresenter::messages(), [
            'threshold_watch' => 'Dikkat eşiği', 'threshold_warning' => 'Uyarı eşiği', 'threshold_critical' => 'Kritik eşiği', 'default_defense_days' => 'Savunma süresi',
        ]);
        $merged = $data + DisciplineSettings::all();
        if (! ($merged['threshold_watch'] < $merged['threshold_warning'] && $merged['threshold_warning'] < $merged['threshold_critical'])) {
            return response()->json(['message' => 'Eşikler küçükten büyüğe olmalı (dikkat < uyarı < kritik).', 'errors' => ['threshold_warning' => ['Eşikler küçükten büyüğe olmalı (dikkat < uyarı < kritik).']]], 422);
        }
        $before = DisciplineSettings::all();
        DisciplineSettings::put($data);
        Audit::log('discipline.settings_updated', 'Disiplin ayarlarını güncelledi.', null, ['before' => array_intersect_key($before, $data), 'after' => $data]);

        return $this->ok('Disiplin ayarları kaydedildi.', ['data' => DisciplineSettings::all()]);
    }

    /** Öğrenci profili "Disiplin" sekmesi. */
    public function student(Request $request, Student $student): JsonResponse
    {
        $incidents = DisciplineIncident::query()
            ->whereHas('participants', fn ($q) => $q->where('student_id', $student->id))
            ->with(['participants' => fn ($q) => $q->where('student_id', $student->id)->with('behavior:id,name,kind'), 'reporter:id,name'])
            ->orderByDesc('occurred_at')->limit(100)->get();

        $sanctions = DisciplineSanction::query()->where('student_id', $student->id)
            ->with(['type', 'incident:id,incident_no', 'decider:id,name', 'appeals.decider:id,name'])->orderByDesc('id')->get();
        $defenses = DisciplineDefense::query()->where('student_id', $student->id)->with('incident:id,incident_no')->orderByDesc('requested_at')->get();

        return response()->json(['data' => [
            'standing' => $this->standing->forStudent($student->id),
            'suspended_today' => $sanctions->first(fn ($s) => in_array($s->status, ['active', 'appealed'], true) && $s->type->is_suspension
                && $s->starts_on?->toDateString() <= today()->toDateString() && $s->ends_on?->toDateString() >= today()->toDateString()) !== null,
            'incidents' => $incidents->map(function ($i) {
                $p = $i->participants->first();

                return [
                    'id' => $i->id, 'incident_no' => $i->incident_no, 'kind' => $i->kind, 'occurred_at' => $i->occurred_at->toAtomString(),
                    'status' => $i->status, 'status_label' => C::INCIDENT_STATUSES[$i->status] ?? $i->status, 'outcome' => $i->outcome,
                    'severity' => $i->severity, 'severity_label' => C::SEVERITIES[$i->severity] ?? $i->severity,
                    'role' => $p?->role, 'role_label' => C::ROLES[$p?->role] ?? null, 'behavior' => $p?->behavior?->name,
                    'penalty_points' => (int) $p?->penalty_points, 'merit_points' => (int) $p?->merit_points,
                    'location' => $i->location, 'reporter' => $i->reporter?->name,
                ];
            })->values(),
            'sanctions' => $sanctions->map(fn ($s) => DisciplinePresenter::sanction($s))->values(),
            'defenses' => $defenses->map(fn ($d) => DisciplinePresenter::defense($d))->values(),
        ]]);
    }
}
