<?php

namespace App\Http\Controllers\Api\Academic;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Classroom;
use App\Services\Academic\TimeSlots;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClassroomController extends ApiController
{
    /** Doluluk için haftalık pencere: Pzt–Cmt 08:00–21:00 (6 gün × 13 saat). */
    private const WEEK_WINDOW_MINUTES = 6 * 13 * 60;

    public function index(Request $request): JsonResponse
    {
        $now = CarbonImmutable::now();
        $query = Classroom::query()
            ->addSelect([
                'weekly_minutes' => DB::table('lesson_schedules')->selectRaw('COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(ends_at, starts_at))) / 60, 0)')->whereColumn('classroom_id', 'classrooms.id')->whereNull('deleted_at')
                    ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now->toDateString()))->where('valid_from', '<=', $now->addDays(6)->toDateString()),
                'today_sessions' => DB::table('lesson_sessions')->selectRaw('COUNT(*)')->whereColumn('classroom_id', 'classrooms.id')->where('date', $now->toDateString())->where('status', '!=', 'cancelled'),
                'current_session' => DB::table('lesson_sessions as ls')->join('class_groups as cg', 'cg.id', '=', 'ls.class_group_id')->select('cg.name')->whereColumn('ls.classroom_id', 'classrooms.id')
                    ->where('ls.status', '!=', 'cancelled')->where('ls.starts_at', '<=', $now)->where('ls.ends_at', '>', $now)->limit(1),
            ])
            ->when($request->query('q'), fn ($q, $s) => $q->where('name', 'like', "%$s%"))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('status', 'active') === 'active', fn ($q) => $q->where('is_active', true));
        $this->applySort($query, $request, ['name' => 'name', 'capacity' => 'capacity', 'kind' => 'kind', 'floor' => 'floor', 'weekly_minutes' => 'weekly_minutes'], 'name');

        return $this->paginated($query->paginate($this->perPage($request, 50)), fn (Classroom $c) => [
            ...$c->only(['id', 'name', 'kind', 'capacity', 'floor', 'features', 'is_active']),
            'kind_label' => Classroom::KINDS[$c->kind] ?? $c->kind,
            'occupancy' => (int) round(TimeSlots::occupancy((int) $c->weekly_minutes, self::WEEK_WINDOW_MINUTES)),
            'weekly_minutes' => (int) $c->weekly_minutes,
            'today_sessions' => (int) $c->today_sessions,
            'current_session' => $c->current_session,
        ], ['kinds' => Classroom::KINDS]);
    }

    public function show(Classroom $classroom): JsonResponse
    {
        $now = CarbonImmutable::now();
        $today = DB::table('lesson_sessions as ls')->join('subjects as s', 's.id', '=', 'ls.subject_id')->join('class_groups as cg', 'cg.id', '=', 'ls.class_group_id')->join('teachers as t', 't.id', '=', 'ls.teacher_id')
            ->where('ls.classroom_id', $classroom->id)->where('ls.date', $now->toDateString())->orderBy('ls.starts_at')
            ->get(['ls.id', 'ls.starts_at', 'ls.ends_at', 'ls.status', 'ls.attendance_taken_at', 's.name as subject', 's.color as subject_color', 'cg.name as class_group', 'cg.id as class_group_id', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")])
            ->map(function ($s) use ($now) {
                $s->phase = $s->status === 'cancelled' ? 'cancelled' : ($now->lt($s->starts_at) ? 'upcoming' : ($now->lt($s->ends_at) ? 'in_progress' : 'done'));

                return $s;
            });

        $studyToday = DB::table('study_sessions as ss')->leftJoin('teachers as t', 't.id', '=', 'ss.teacher_id')->leftJoin('subjects as s', 's.id', '=', 'ss.subject_id')
            ->where('ss.classroom_id', $classroom->id)->whereIn('ss.status', ['approved', 'requested', 'completed'])->whereDate('ss.starts_at', $now->toDateString())->orderBy('ss.starts_at')
            ->get(['ss.id', 'ss.kind', 'ss.starts_at', 'ss.ends_at', 'ss.status', 'ss.topic', 's.name as subject', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")]);

        $schedules = DB::table('lesson_schedules as ls')->join('subjects as s', 's.id', '=', 'ls.subject_id')->join('class_groups as cg', 'cg.id', '=', 'ls.class_group_id')->join('teachers as t', 't.id', '=', 'ls.teacher_id')
            ->where('ls.classroom_id', $classroom->id)->whereNull('ls.deleted_at')
            ->where(fn ($q) => $q->whereNull('ls.valid_until')->orWhere('ls.valid_until', '>=', $now->toDateString()))
            ->orderBy('ls.weekday')->orderBy('ls.starts_at')
            ->get(['ls.id', 'ls.weekday', 'ls.starts_at', 'ls.ends_at', 's.name as subject', 's.color as subject_color', 'cg.name as class_group', 'cg.id as class_group_id', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")]);

        $perDay = [];
        foreach (TimeSlots::WEEKDAYS as $d => $label) {
            $ranges = $schedules->where('weekday', $d)->map(fn ($r) => [TimeSlots::toMinutes($r->starts_at), TimeSlots::toMinutes($r->ends_at)])->values()->all();
            $used = array_sum(array_map(fn ($r) => $r[1] - $r[0], TimeSlots::merge($ranges)));
            $perDay[] = ['weekday' => $d, 'label' => $label, 'minutes' => $used, 'occupancy' => (int) round(TimeSlots::occupancy($used, 13 * 60)), 'lessons' => count($ranges)];
        }
        $weeklyMinutes = array_sum(array_column($perDay, 'minutes'));

        return response()->json([
            'classroom' => [...$classroom->only(['id', 'name', 'kind', 'capacity', 'floor', 'features', 'is_active']), 'kind_label' => Classroom::KINDS[$classroom->kind] ?? $classroom->kind],
            'today' => $today,
            'study_today' => $studyToday,
            'schedules' => $schedules->map(fn ($r) => [...(array) $r, 'weekday_label' => TimeSlots::WEEKDAYS[$r->weekday] ?? '']),
            'occupancy' => ['weekly' => (int) round(TimeSlots::occupancy($weeklyMinutes, self::WEEK_WINDOW_MINUTES)), 'weekly_minutes' => $weeklyMinutes, 'per_day' => $perDay],
            'homeroom_of' => DB::table('class_groups')->where('homeroom_classroom_id', $classroom->id)->whereNull('deleted_at')->where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $classroom = Classroom::query()->create($this->validated($request));
        Audit::log('classroom.created', "{$classroom->name} dersliğini oluşturdu (kapasite {$classroom->capacity}).", $classroom);

        return response()->json(['message' => 'Derslik oluşturuldu.', 'id' => $classroom->id], 201);
    }

    public function update(Request $request, Classroom $classroom): JsonResponse
    {
        $classroom->fill($this->validated($request, $classroom))->save();
        $changes = Audit::diff($classroom);
        if ($changes['after'] !== []) {
            Audit::log('classroom.updated', "{$classroom->name} dersliğini güncelledi.", $classroom, $changes);
        }

        return $this->ok('Derslik güncellendi.');
    }

    public function destroy(Classroom $classroom): JsonResponse
    {
        if (DB::table('lesson_schedules')->where('classroom_id', $classroom->id)->whereNull('deleted_at')->exists()) {
            throw new BusinessRuleException('Ders programında kullanılan derslik silinemez; önce dersleri başka dersliğe taşıyın.', 'classroom_in_use');
        }
        $classroom->forceFill(['is_active' => false])->save();
        $classroom->delete();
        Audit::log('classroom.deleted', "{$classroom->name} dersliğini sildi.", $classroom);

        return $this->ok('Derslik silindi.');
    }

    private function validated(Request $request, ?Classroom $classroom = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('classrooms', 'name')->where('branch_id', $classroom?->branch_id ?? app(\App\Support\BranchContext::class)->id())->ignore($classroom?->id)->whereNull('deleted_at')],
            'kind' => ['required', Rule::in(array_keys(Classroom::KINDS))],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'floor' => ['nullable', 'string', 'max:20'],
            'features' => ['nullable', 'string', 'max:300'],
            'is_active' => ['boolean'],
        ], ['name.unique' => 'Bu adda bir derslik zaten var.'], [
            'name' => 'Derslik adı', 'kind' => 'Derslik türü', 'capacity' => 'Kapasite', 'floor' => 'Kat', 'features' => 'Donanım', 'is_active' => 'Durum',
        ]);
    }
}
