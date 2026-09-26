<?php

namespace App\Http\Controllers\Api\Academic;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\LessonSchedule;
use App\Models\LessonSession;
use App\Models\Student;
use App\Services\Academic\ScheduleService;
use App\Services\Academic\TimeSlots;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Ders programı: haftalık şablon ızgarası (sınıf/öğretmen/derslik/öğrenci bazlı),
 * günlük ve aylık oturum takvimi, sürükle-bırak taşıma ve tek seferlik oturum işlemleri.
 */
class ScheduleController extends ApiController
{
    public const VIEWS = ['class_group', 'teacher', 'classroom', 'student'];

    public function __construct(private readonly ScheduleService $schedules) {}

    /** Haftalık ızgara: seçili haftada geçerli şablonlar + o haftanın oturum durumu + etütler. */
    public function week(Request $request): JsonResponse
    {
        [$view, $id] = $this->viewParams($request);
        $date = $request->date('date') ? CarbonImmutable::parse($request->date('date')) : CarbonImmutable::today();
        $start = TimeSlots::weekStart($date);
        $end = $start->addDays(6);

        $groupIds = $view === 'student' ? $this->studentGroupIds($id) : null;

        $templates = LessonSchedule::query()
            ->with(['subject:id,name,short_name,color', 'teacher:id,first_name,last_name,color', 'classroom:id,name', 'classGroup:id,name'])
            ->where('valid_from', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $start->toDateString()))
            ->when($view === 'class_group', fn ($q) => $q->where('class_group_id', $id))
            ->when($view === 'teacher', fn ($q) => $q->where('teacher_id', $id))
            ->when($view === 'classroom', fn ($q) => $q->where('classroom_id', $id))
            ->when($view === 'student', fn ($q) => $q->whereIn('class_group_id', $groupIds))
            ->orderBy('weekday')->orderBy('starts_at')->get();

        $sessions = LessonSession::query()->whereIn('lesson_schedule_id', $templates->pluck('id'))
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['id', 'lesson_schedule_id', 'date', 'status', 'cancel_reason', 'topic_note', 'topic_id', 'attendance_taken_at', 'classroom_id', 'teacher_id'])
            ->keyBy('lesson_schedule_id');

        // Tek seferlik değişikliği (farklı derslik/öğretmen) ızgarada göstermek için
        $overrideRooms = DB::table('classrooms')->whereIn('id', $sessions->pluck('classroom_id')->unique())->pluck('name', 'id');
        $overrideTeachers = DB::table('teachers')->whereIn('id', $sessions->pluck('teacher_id')->unique())->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $sessionTopics = $this->topicsForSessions($sessions->pluck('id'));

        $studies = collect();
        if ($view !== 'class_group') {
            $studies = DB::table('study_sessions as ss')->leftJoin('teachers as t', 't.id', '=', 'ss.teacher_id')->leftJoin('subjects as s', 's.id', '=', 'ss.subject_id')->leftJoin('classrooms as c', 'c.id', '=', 'ss.classroom_id')
                ->whereIn('ss.status', ['requested', 'approved', 'completed'])->where('ss.branch_id', app(\App\Support\BranchContext::class)->require())
                ->whereBetween('ss.starts_at', [$start->startOfDay(), $end->endOfDay()])
                ->when($view === 'teacher', fn ($q) => $q->where('ss.teacher_id', $id))
                ->when($view === 'classroom', fn ($q) => $q->where('ss.classroom_id', $id))
                ->when($view === 'student', fn ($q) => $q->whereExists(fn ($s) => $s->from('study_session_student')->whereColumn('study_session_id', 'ss.id')->where('student_id', $id)))
                ->orderBy('ss.starts_at')
                ->get(['ss.id', 'ss.kind', 'ss.status', 'ss.starts_at', 'ss.ends_at', 'ss.topic', 's.name as subject', 'c.name as classroom', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")])
                ->map(fn ($r) => [
                    'id' => $r->id, 'kind' => $r->kind, 'status' => $r->status, 'date' => substr($r->starts_at, 0, 10), 'weekday' => CarbonImmutable::parse($r->starts_at)->dayOfWeekIso,
                    'starts_at' => substr($r->starts_at, 11, 5), 'ends_at' => substr($r->ends_at, 11, 5), 'topic' => $r->topic, 'subject' => $r->subject, 'classroom' => $r->classroom, 'teacher' => $r->teacher,
                ]);
        }

        $items = $templates->map(function (LessonSchedule $t) use ($sessions, $start, $overrideRooms, $overrideTeachers, $sessionTopics) {
            $s = $sessions[$t->id] ?? null;
            $date = $start->addDays($t->weekday - 1);
            $inRange = $date->gte($t->valid_from) && (! $t->valid_until || $date->lte($t->valid_until));

            return [
                'id' => $t->id, 'weekday' => $t->weekday, 'starts_at' => substr($t->starts_at, 0, 5), 'ends_at' => substr($t->ends_at, 0, 5),
                'valid_from' => $t->valid_from->toDateString(), 'valid_until' => $t->valid_until?->toDateString(), 'active_this_week' => $inRange, 'is_locked' => (bool) $t->is_locked,
                'subject' => ['id' => $t->subject->id, 'name' => $t->subject->name, 'short_name' => $t->subject->short_name, 'color' => $t->subject->color],
                'teacher' => ['id' => $t->teacher->id, 'name' => $t->teacher->full_name, 'color' => $t->teacher->color],
                'classroom' => ['id' => $t->classroom->id, 'name' => $t->classroom->name],
                'class_group' => ['id' => $t->classGroup->id, 'name' => $t->classGroup->name],
                'session' => $s ? [
                    'id' => $s->id, 'date' => $s->date->toDateString(), 'status' => $s->status, 'cancel_reason' => $s->cancel_reason, 'topic_note' => $s->topic_note, 'topic_id' => $s->topic_id,
                    'topics' => $sessionTopics[$s->id] ?? [],
                    'attendance_taken' => (bool) $s->attendance_taken_at,
                    'classroom_override' => $s->classroom_id !== $t->classroom_id ? ['id' => $s->classroom_id, 'name' => $overrideRooms[$s->classroom_id] ?? null] : null,
                    'teacher_override' => $s->teacher_id !== $t->teacher_id ? ['id' => $s->teacher_id, 'name' => isset($overrideTeachers[$s->teacher_id]) ? $overrideTeachers[$s->teacher_id]->first_name.' '.$overrideTeachers[$s->teacher_id]->last_name : null] : null,
                ] : null,
            ];
        });

        return response()->json([
            'view' => $view, 'id' => $id, 'week_start' => $start->toDateString(), 'week_end' => $end->toDateString(),
            'days' => collect(range(0, 6))->map(fn ($i) => ['date' => $start->addDays($i)->toDateString(), 'weekday' => $i + 1, 'label' => TimeSlots::WEEKDAYS[$i + 1], 'is_today' => $start->addDays($i)->isToday()]),
            'items' => $items->values(),
            'studies' => $studies->values(),
            'range' => $this->hourRange($items->pluck('starts_at')->concat($studies->pluck('starts_at')), $items->pluck('ends_at')->concat($studies->pluck('ends_at'))),
        ]);
    }

    /** Somut oturumlar (günlük liste / aylık takvim). */
    public function sessions(Request $request): JsonResponse
    {
        [$view, $id] = $this->viewParams($request, allowAll: true);
        $from = $request->date('from') ? CarbonImmutable::parse($request->date('from')) : CarbonImmutable::today();
        $to = $request->date('to') ? CarbonImmutable::parse($request->date('to')) : $from;
        if ($from->diffInDays($to) > 62) {
            throw new BusinessRuleException('En fazla 62 günlük aralık sorgulanabilir.', 'range_too_wide');
        }
        $groupIds = $view === 'student' ? $this->studentGroupIds($id) : null;

        $rows = LessonSession::query()
            ->with(['subject:id,name,short_name,color', 'teacher:id,first_name,last_name,color', 'classroom:id,name', 'classGroup:id,name', 'topics:id,name,outcome_code'])
            ->withCount(['attendances as present_count' => fn ($q) => $q->whereIn('status', ['present', 'late']), 'attendances as absent_count' => fn ($q) => $q->where('status', 'absent')])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($view === 'class_group', fn ($q) => $q->where('class_group_id', $id))
            ->when($view === 'teacher', fn ($q) => $q->where('teacher_id', $id))
            ->when($view === 'classroom', fn ($q) => $q->where('classroom_id', $id))
            ->when($view === 'student', fn ($q) => $q->whereIn('class_group_id', $groupIds))
            ->orderBy('starts_at')->orderBy('classroom_id')->get();

        $now = CarbonImmutable::now();

        return response()->json(['data' => $rows->map(fn (LessonSession $s) => [
            'id' => $s->id, 'schedule_id' => $s->lesson_schedule_id, 'date' => $s->date->toDateString(), 'starts_at' => $s->starts_at->format('H:i'), 'ends_at' => $s->ends_at->format('H:i'),
            'status' => $s->status, 'cancel_reason' => $s->cancel_reason, 'topic_note' => $s->topic_note, 'topic_id' => $s->topic_id, 'attendance_taken' => (bool) $s->attendance_taken_at,
            'topics' => $s->topics->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'outcome_code' => $t->outcome_code])->values(),
            'present_count' => (int) $s->present_count, 'absent_count' => (int) $s->absent_count, 'makeup_of_id' => $s->makeup_of_id, 'holiday_id' => $s->holiday_id,
            'phase' => $s->status === 'cancelled' ? 'cancelled' : ($now->lt($s->starts_at) ? 'upcoming' : ($now->lt($s->ends_at) ? 'in_progress' : 'done')),
            'subject' => ['id' => $s->subject->id, 'name' => $s->subject->name, 'short_name' => $s->subject->short_name, 'color' => $s->subject->color],
            'teacher' => ['id' => $s->teacher->id, 'name' => $s->teacher->full_name, 'color' => $s->teacher->color],
            'classroom' => ['id' => $s->classroom->id, 'name' => $s->classroom->name],
            'class_group' => ['id' => $s->classGroup->id, 'name' => $s->classGroup->name],
        ])]);
    }

    /** Kaydetmeden çakışma önizlemesi (form yazarken). */
    public function check(Request $request): JsonResponse
    {
        $data = $this->validatedTemplate($request, partial: true);
        $ignore = $request->integer('ignore_id') ?: null;
        if ($ignore) {
            $existing = LessonSchedule::query()->findOrFail($ignore);
            $data = array_merge($existing->only(['class_group_id', 'subject_id', 'teacher_id', 'classroom_id', 'weekday', 'starts_at', 'ends_at']), ['valid_from' => $existing->valid_from->toDateString(), 'valid_until' => $existing->valid_until?->toDateString()], $data);
        }
        foreach (['class_group_id', 'teacher_id', 'classroom_id', 'weekday', 'starts_at', 'ends_at'] as $k) {
            if (empty($data[$k])) {
                return response()->json(['conflicts' => []]);
            }
        }

        return response()->json(['conflicts' => $this->schedules->conflictsFor($data, $ignore)]);
    }

    public function store(Request $request): JsonResponse
    {
        $schedule = $this->schedules->create($this->validatedTemplate($request));

        return response()->json(['message' => 'Ders programa eklendi.', 'id' => $schedule->id], 201);
    }

    public function update(Request $request, LessonSchedule $schedule): JsonResponse
    {
        $this->schedules->update($schedule, $this->validatedTemplate($request, partial: true));

        return $this->ok('Ders güncellendi; gelecekteki oturumlar yenilendi.');
    }

    public function destroy(LessonSchedule $schedule): JsonResponse
    {
        $this->schedules->delete($schedule);

        return $this->ok('Ders programdan kaldırıldı.');
    }

    // ------------------------------------------------------------------ tek seferlik oturum işlemleri

    public function cancelSession(Request $request, LessonSession $session): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']], ['reason.required' => 'İptal gerekçesi zorunludur.']);
        $this->schedules->cancelSession($session, $data['reason']);

        return $this->ok('Ders iptal edildi.');
    }

    public function restoreSession(LessonSession $session): JsonResponse
    {
        $this->schedules->restoreSession($session);

        return $this->ok('Ders iptali geri alındı.');
    }

    public function reassignSession(Request $request, LessonSession $session): JsonResponse
    {
        $data = $request->validate(['classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')], 'teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')]]);
        if (empty($data['classroom_id']) && empty($data['teacher_id'])) {
            throw new BusinessRuleException('Derslik ya da öğretmen seçin.', 'nothing_to_change');
        }
        $this->schedules->reassignSession($session, $data['classroom_id'] ?? null, $data['teacher_id'] ?? null);

        return $this->ok('Bu derse özel değişiklik kaydedildi.');
    }

    /** Program botuna karşı kilit (kilitli ders bot çalıştırmalarında olduğu gibi korunur). */
    public function lock(Request $request, LessonSchedule $schedule): JsonResponse
    {
        $data = $request->validate(['locked' => ['required', 'boolean']]);
        $this->schedules->setLocked($schedule, (bool) $data['locked']);

        return $this->ok($data['locked'] ? 'Ders kilitlendi; program botu bu derse dokunmaz.' : 'Kilit kaldırıldı.');
    }

    public function topicSession(Request $request, LessonSession $session): JsonResponse
    {
        $data = $request->validate([
            // Çoklu konu (yeni akış); tekil topic_id geriye dönük uyumluluk için kabul edilir.
            'topic_ids' => ['nullable', 'array'],
            'topic_ids.*' => ['integer', Rule::exists('topics', 'id')->where('subject_id', $session->subject_id)],
            'topic_id' => ['nullable', 'integer', Rule::exists('topics', 'id')->where('subject_id', $session->subject_id)],
            'topic_note' => ['nullable', 'string', 'max:500'],
        ]);
        $ids = $data['topic_ids'] ?? (! empty($data['topic_id']) ? [$data['topic_id']] : []);
        $this->schedules->setTopic($session, $ids, $data['topic_note'] ?? null);

        return $this->ok('İşlenen konu kaydedildi.');
    }

    /**
     * Oturum kimliklerine göre işlenen konuları toplu getirir (N+1'siz).
     *
     * @param  \Illuminate\Support\Collection<int,int>  $sessionIds
     * @return array<int,array<int,array{id:int,name:string,outcome_code:?string}>>
     */
    private function topicsForSessions($sessionIds): array
    {
        $sessionIds = collect($sessionIds)->filter()->unique()->values();
        if ($sessionIds->isEmpty()) {
            return [];
        }

        return DB::table('lesson_session_topic as lst')
            ->join('topics as t', 't.id', '=', 'lst.topic_id')
            ->whereIn('lst.lesson_session_id', $sessionIds)
            ->orderBy('lst.sort')->orderBy('t.name')
            ->get(['lst.lesson_session_id as sid', 't.id', 't.name', 't.outcome_code'])
            ->groupBy('sid')
            ->map(fn ($g) => $g->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'outcome_code' => $r->outcome_code])->values()->all())
            ->all();
    }

    // ------------------------------------------------------------------ yardımcılar

    /** @return array{0:string,1:int} */
    private function viewParams(Request $request, bool $allowAll = false): array
    {
        $view = (string) $request->query('view', 'class_group');
        if (! in_array($view, $allowAll ? [...self::VIEWS, 'all'] : self::VIEWS, true)) {
            throw new BusinessRuleException('Geçersiz görünüm.', 'invalid_view');
        }
        $id = $request->integer('id');
        if ($view !== 'all' && ! $id) {
            throw new BusinessRuleException('Görünüm için bir kayıt seçin.', 'missing_id');
        }
        if ($view === 'student' && ! $request->user()->can('students.view')) {
            abort(403);
        }

        return [$view, $id];
    }

    private function studentGroupIds(int $studentId): array
    {
        return Student::query()->findOrFail($studentId)->currentClassGroups()->pluck('class_groups.id')->all();
    }

    private function hourRange($starts, $ends): array
    {
        $min = $starts->filter()->map(fn ($t) => TimeSlots::toMinutes($t))->min();
        $max = $ends->filter()->map(fn ($t) => TimeSlots::toMinutes($t))->max();

        return ['start' => min(8, $min !== null ? intdiv($min, 60) : 8), 'end' => max(19, $max !== null ? (int) ceil($max / 60) : 19)];
    }

    private function validatedTemplate(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'class_group_id' => [$req, 'integer', Rule::exists('class_groups', 'id')],
            'subject_id' => [$req, 'integer', Rule::exists('subjects', 'id')],
            'teacher_id' => [$req, 'integer', Rule::exists('teachers', 'id')],
            'classroom_id' => [$req, 'integer', Rule::exists('classrooms', 'id')],
            'weekday' => [$req, 'integer', 'min:1', 'max:7'],
            'starts_at' => [$req, 'date_format:H:i'],
            'ends_at' => [$req, 'date_format:H:i'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'academic_term_id' => ['nullable', 'integer', Rule::exists('academic_terms', 'id')],
        ], [], [
            'class_group_id' => 'Sınıf', 'subject_id' => 'Ders', 'teacher_id' => 'Öğretmen', 'classroom_id' => 'Derslik', 'weekday' => 'Gün',
            'starts_at' => 'Başlangıç saati', 'ends_at' => 'Bitiş saati', 'valid_from' => 'Geçerlilik başlangıcı', 'valid_until' => 'Geçerlilik bitişi', 'academic_term_id' => 'Eğitim dönemi',
        ]);
    }
}
