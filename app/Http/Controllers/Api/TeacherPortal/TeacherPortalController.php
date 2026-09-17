<?php

namespace App\Http\Controllers\Api\TeacherPortal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Concerns\IsoJson;
use App\Http\Middleware\EnsurePortalTeacher;
use App\Models\ContactRequest;
use App\Models\Student;
use App\Models\StudentObservation;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Portal\AnnouncementAudience;
use App\Services\Teachers\TeacherScope;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Öğretmen portalı — okuma uçları + gözlem notu + veli talepleri + duyurular.
 * Öğretmen EnsurePortalTeacher ile oturumdan çözülür; her sınıf/öğrenci erişimi TeacherScope ile
 * sınırlanır (başka sınıfın öğrencisi → 403). Finans, rehberlik gizli notu, telefon/TC dönmez.
 */
class TeacherPortalController extends ApiController
{
    use IsoJson;

    public const ATTENDANCE_LABELS = ['present' => 'Geldi', 'late' => 'Geç kaldı', 'absent' => 'Gelmedi', 'excused' => 'İzinli', 'medical' => 'Raporlu'];

    public const HOMEWORK_LABELS = ['assigned' => 'Yapılacak', 'seen' => 'Görüldü', 'submitted' => 'Teslim edildi', 'late' => 'Geç teslim', 'missed' => 'Teslim edilmedi'];

    /** Yoklama bu kadar gün geriye kadar düzeltilebilir. */
    public const ATTENDANCE_EDIT_DAYS = 7;

    protected function teacher(Request $request): Teacher
    {
        return $request->attributes->get(EnsurePortalTeacher::ATTRIBUTE);
    }

    protected function scope(Request $request): TeacherScope
    {
        return $request->attributes->get(EnsurePortalTeacher::SCOPE_ATTRIBUTE);
    }

    // ------------------------------------------------------------------ özet

    public function summary(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);
        $scope = $this->scope($request);
        $today = CarbonImmutable::today();

        $todayLessons = $this->sessions($teacher, $today, $today);
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $weekCount = DB::table('lesson_sessions')->where('teacher_id', $teacher->id)->where('status', '!=', 'cancelled')
            ->whereBetween('date', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])->count();

        $homeworkToGrade = DB::table('homework as h')->join('homework_submissions as hs', 'hs.homework_id', '=', 'h.id')
            ->join('subjects as s', 's.id', '=', 'h.subject_id')->leftJoin('class_groups as g', 'g.id', '=', 'h.class_group_id')
            ->where('h.teacher_id', $teacher->id)->whereNull('h.deleted_at')
            ->whereIn('hs.status', ['submitted', 'late'])->whereNull('hs.score')->whereNull('hs.graded_at')
            ->groupBy('h.id', 'h.title', 'h.due_at', 's.name', 'g.name')
            ->orderByDesc(DB::raw('COUNT(*)'))->limit(6)
            ->get(['h.id', 'h.title', 'h.due_at', 's.name as subject', 'g.name as class_group', DB::raw('COUNT(*) AS pending_count')]);

        $openHomework = DB::table('homework')->where('teacher_id', $teacher->id)->whereNull('deleted_at')->where('due_at', '>', now())->count();

        $requests = ContactRequest::query()->where('teacher_id', $teacher->id)->where('status', 'open')->count();

        $announcements = AnnouncementAudience::forTeacher($teacher->branch_id)->limit(3)->get(['id', 'title', 'body', 'published_at']);
        $unread = AnnouncementAudience::unreadCount(AnnouncementAudience::forTeacher($teacher->branch_id), $request->user()->id);

        $upcomingExams = $this->upcomingExams($teacher->branch_id, 14)->take(4)->values();

        $observations = StudentObservation::query()->where('teacher_id', $teacher->id)->where('created_at', '>=', now()->subDays(7))->count();

        return $this->isoJson([
            'teacher' => $this->identity($teacher),
            'today' => ['date' => $today->toDateString(), 'lessons' => $todayLessons],
            'counts' => [
                'classes' => $scope->groupIds()->count(),
                'students' => $scope->studentIds()->count(),
                'week_lessons' => $weekCount,
                'open_homework' => $openHomework,
                'to_grade' => (int) $homeworkToGrade->sum('pending_count') + $this->toGradeRemainder($teacher, $homeworkToGrade->pluck('id')),
                'open_requests' => $requests,
                'unread_announcements' => $unread,
                'observations_week' => $observations,
            ],
            'pending_attendance' => $this->pendingAttendance($teacher),
            'homework_to_grade' => $homeworkToGrade,
            'upcoming_exams' => $upcomingExams,
            'announcements' => $announcements,
        ]);
    }

    /** Listelenmeyen (ilk 6 dışındaki) ödevlerdeki bekleyen teslimler. */
    private function toGradeRemainder(Teacher $teacher, Collection $listedIds): int
    {
        return DB::table('homework as h')->join('homework_submissions as hs', 'hs.homework_id', '=', 'h.id')
            ->where('h.teacher_id', $teacher->id)->whereNull('h.deleted_at')->whereNotIn('h.id', $listedIds)
            ->whereIn('hs.status', ['submitted', 'late'])->whereNull('hs.score')->whereNull('hs.graded_at')->count();
    }

    public function schedule(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);
        $ref = $this->parseDay($request->query('date')) ?? CarbonImmutable::today();
        $start = $ref->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->addDays(6);

        $sessions = $this->sessions($teacher, $start, $end);
        $study = $this->studyRows($teacher, $start->startOfDay(), $end->endOfDay());

        $days = [];
        for ($d = $start; $d <= $end; $d = $d->addDay()) {
            $key = $d->toDateString();
            $days[] = [
                'date' => $key,
                'lessons' => $sessions->filter(fn ($s) => $s->date === $key)->values(),
                'study' => $study->filter(fn ($s) => substr((string) $s->starts_at, 0, 10) === $key)->values(),
            ];
        }

        return $this->isoJson([
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'total_lessons' => $sessions->where('status', '!=', 'cancelled')->count(),
            'days' => $days,
        ]);
    }

    // ------------------------------------------------------------------ sınıflar / öğrenciler

    public function classes(Request $request): JsonResponse
    {
        return $this->isoJson(['data' => $this->scope($request)->groupsWithMeta()]);
    }

    public function classShow(Request $request, int $group): JsonResponse
    {
        $teacher = $this->teacher($request);
        $scope = $this->scope($request);
        $scope->assertGroup($group);

        $meta = $scope->groupsWithMeta()->firstWhere('id', $group);

        $students = DB::table('class_group_student as cgs')->join('students as st', 'st.id', '=', 'cgs.student_id')
            ->where('cgs.class_group_id', $group)->whereNull('cgs.left_on')->whereNull('st.deleted_at')
            ->orderBy('st.first_name')->orderBy('st.last_name')
            ->get(['st.id', 'st.full_name', 'st.student_no', 'st.photo_path', 'st.status']);
        $ids = $students->pluck('id');

        $since = now()->subDays(30)->toDateString();
        $att = DB::table('attendances')->whereIn('student_id', $ids)->where('date', '>=', $since)
            ->groupBy('student_id')
            ->selectRaw("student_id, COUNT(*) AS total, SUM(status IN ('present','late')) AS attended, SUM(status='absent') AS absent, SUM(status='late') AS late")
            ->get()->keyBy('student_id');

        $lastNet = $this->lastNets($ids);

        $hw = DB::table('homework_submissions as hs')->join('homework as h', 'h.id', '=', 'hs.homework_id')
            ->where('h.teacher_id', $teacher->id)->whereNull('h.deleted_at')->whereIn('hs.student_id', $ids)->where('h.due_at', '<=', now())
            ->groupBy('hs.student_id')
            ->selectRaw("hs.student_id, COUNT(*) AS total, SUM(hs.status IN ('submitted','late')) AS done, AVG(hs.score) AS avg_score")
            ->get()->keyBy('student_id');

        $points = DB::table('student_observations')->whereIn('student_id', $ids)->whereNull('deleted_at')
            ->where('created_at', '>=', now()->subDays(60))
            ->groupBy('student_id')->selectRaw('student_id, SUM(points) AS points, COUNT(*) AS notes')->get()->keyBy('student_id');

        $rows = $students->map(function ($s) use ($att, $lastNet, $hw, $points) {
            $a = $att[$s->id] ?? null;
            $h = $hw[$s->id] ?? null;
            $p = $points[$s->id] ?? null;

            return [
                'id' => (int) $s->id,
                'full_name' => $s->full_name,
                'student_no' => $s->student_no,
                'photo_url' => $s->photo_path ? Storage::disk('public')->url($s->photo_path) : null,
                'attendance_rate' => $a && $a->total ? (int) round($a->attended / $a->total * 100) : null,
                'absent_30' => (int) ($a->absent ?? 0),
                'late_30' => (int) ($a->late ?? 0),
                'last_net' => $lastNet[$s->id] ?? null,
                'homework_done' => (int) ($h->done ?? 0),
                'homework_total' => (int) ($h->total ?? 0),
                'homework_avg' => $h && $h->avg_score !== null ? round((float) $h->avg_score, 1) : null,
                'points_60' => (int) ($p->points ?? 0),
                'notes_60' => (int) ($p->notes ?? 0),
            ];
        });

        $upcoming = DB::table('lesson_sessions as ls')->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->leftJoin('classrooms as c', 'c.id', '=', 'ls.classroom_id')
            ->where('ls.teacher_id', $teacher->id)->where('ls.class_group_id', $group)
            ->where('ls.date', '>=', now()->toDateString())->where('ls.status', '!=', 'cancelled')
            ->orderBy('ls.starts_at')->limit(6)
            ->get(['ls.id', 'ls.date', 'ls.starts_at', 'ls.ends_at', 's.name as subject', 'c.name as classroom', 'ls.attendance_taken_at']);

        $homework = DB::table('homework as h')->join('subjects as s', 's.id', '=', 'h.subject_id')
            ->where('h.teacher_id', $teacher->id)->where('h.class_group_id', $group)->whereNull('h.deleted_at')
            ->orderByDesc('h.due_at')->limit(6)
            ->get(['h.id', 'h.title', 'h.due_at', 's.name as subject']);

        return $this->isoJson([
            'group' => $meta,
            'students' => $rows,
            'upcoming_lessons' => $upcoming,
            'homework' => $homework,
        ]);
    }

    /** Öğretmenin tüm öğrencileri (arama). */
    public function students(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $ids = $scope->studentIds();
        $q = trim((string) $request->query('q', ''));

        $rows = $ids->isEmpty() ? collect() : DB::table('students as st')->whereIn('st.id', $ids)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('st.full_name', 'like', '%'.addcslashes($q, '%_').'%')->orWhere('st.student_no', 'like', addcslashes($q, '%_').'%')))
            ->orderBy('st.first_name')->limit(200)
            ->get(['st.id', 'st.full_name', 'st.student_no', 'st.photo_path']);

        $groups = $rows->isEmpty() ? collect() : DB::table('class_group_student as cgs')->join('class_groups as g', 'g.id', '=', 'cgs.class_group_id')
            ->whereIn('cgs.student_id', $rows->pluck('id'))->whereNull('cgs.left_on')->whereNull('g.deleted_at')->where('g.is_active', true)
            ->get(['cgs.student_id', 'g.name'])->groupBy('student_id');

        return $this->isoJson(['data' => $rows->map(fn ($s) => [
            'id' => (int) $s->id, 'full_name' => $s->full_name, 'student_no' => $s->student_no,
            'photo_url' => $s->photo_path ? Storage::disk('public')->url($s->photo_path) : null,
            'class_groups' => ($groups[$s->id] ?? collect())->pluck('name')->values(),
        ])->values()]);
    }

    public function studentShow(Request $request, int $student): JsonResponse
    {
        $teacher = $this->teacher($request);
        $this->scope($request)->assertStudent($student);
        $s = Student::query()->findOrFail($student);

        $groups = DB::table('class_group_student as cgs')->join('class_groups as g', 'g.id', '=', 'cgs.class_group_id')
            ->leftJoin('programs as p', 'p.id', '=', 'g.program_id')
            ->where('cgs.student_id', $s->id)->whereNull('cgs.left_on')->whereNull('g.deleted_at')
            ->get(['g.id', 'g.name', 'p.name as program']);

        $attSummary = DB::table('attendances')->where('student_id', $s->id)
            ->selectRaw("COUNT(*) AS total, SUM(status='present') AS present, SUM(status='late') AS late, SUM(status='absent') AS absent, SUM(status IN ('excused','medical')) AS excused")->first();
        $attRecent = DB::table('attendances as a')->join('lesson_sessions as ls', 'ls.id', '=', 'a.lesson_session_id')
            ->join('subjects as sub', 'sub.id', '=', 'ls.subject_id')
            ->where('a.student_id', $s->id)->where('a.status', '!=', 'present')
            ->orderByDesc('ls.starts_at')->limit(15)
            ->get(['a.id', 'a.status', 'a.late_minutes', 'ls.starts_at', 'sub.name as subject', DB::raw('(ls.teacher_id = '.(int) $teacher->id.') AS is_mine')])
            ->map(fn ($r) => [...(array) $r, 'is_mine' => (bool) $r->is_mine, 'status_label' => self::ATTENDANCE_LABELS[$r->status] ?? $r->status]);

        $exams = DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->where('r.student_id', $s->id)->where('e.status', 'results_published')->whereNull('e.deleted_at')
            ->orderByDesc('e.exam_date')->limit(12)
            ->get(['r.id', 'e.name', 'e.exam_date', 't.code as type', 'r.net', 'r.correct', 'r.wrong', 'r.blank', 'r.class_rank', 'r.institution_rank', 'e.participant_count']);
        $sections = DB::table('exam_result_sections as rs')->join('exam_sections as es', 'es.id', '=', 'rs.exam_section_id')
            ->whereIn('rs.exam_result_id', $exams->pluck('id'))->orderBy('es.sort')
            ->get(['rs.exam_result_id', 'es.name', 'rs.net'])->groupBy('exam_result_id');
        $exams = $exams->map(fn ($e) => [...(array) $e, 'sections' => ($sections[$e->id] ?? collect())->map(fn ($x) => ['name' => $x->name, 'net' => $x->net])->values()]);

        $homework = DB::table('homework_submissions as hs')->join('homework as h', 'h.id', '=', 'hs.homework_id')
            ->join('subjects as sub', 'sub.id', '=', 'h.subject_id')
            ->where('hs.student_id', $s->id)->whereNull('h.deleted_at')
            ->orderByDesc('h.due_at')->limit(25)
            ->get(['hs.id', 'h.id as homework_id', 'h.title', 'h.due_at', 'hs.status', 'hs.score', 'hs.submitted_at', 'sub.name as subject', DB::raw('(h.teacher_id = '.(int) $teacher->id.') AS is_mine')])
            ->map(fn ($r) => [...(array) $r, 'is_mine' => (bool) $r->is_mine, 'status_label' => self::HOMEWORK_LABELS[$r->status] ?? $r->status]);

        $observations = $this->observationRows(StudentObservation::query()->where('student_id', $s->id), $teacher);

        $goal = DB::table('student_goals')->where('student_id', $s->id)->where('is_active', true)->latest('id')
            ->first(['university', 'department', 'target_tyt_net', 'target_ayt_net']);

        $weak = DB::table('student_topic_stats as sts')->join('topics as tp', 'tp.id', '=', 'sts.topic_id')
            ->join('subjects as sub', 'sub.id', '=', 'tp.subject_id')
            ->where('sts.student_id', $s->id)->where('sts.asked', '>=', 3)
            ->orderByRaw('sts.correct / sts.asked ASC')->limit(6)
            ->get(['tp.name as topic', 'sub.name as subject', 'sts.asked', 'sts.correct', 'sts.wrong'])
            ->map(fn ($r) => [...(array) $r, 'rate' => (int) round($r->correct / max(1, $r->asked) * 100)]);

        return $this->isoJson([
            'student' => [
                'id' => $s->id,
                'full_name' => $s->full_name,
                'first_name' => $s->first_name,
                'student_no' => $s->student_no,
                'photo_url' => $s->photo_path ? Storage::disk('public')->url($s->photo_path) : null,
                'school_name' => $s->school_name,
                'school_grade' => $s->school_grade,
                'field' => $s->field,
                'status_label' => Student::STATUSES[$s->status] ?? $s->status,
                'class_groups' => $groups,
                'target' => $goal ? ['university' => $goal->university, 'department' => $goal->department, 'tyt' => $goal->target_tyt_net, 'ayt' => $goal->target_ayt_net]
                    : ['university' => $s->target_university, 'department' => $s->target_department, 'tyt' => null, 'ayt' => null],
            ],
            'attendance' => ['summary' => array_map('intval', (array) $attSummary), 'recent' => $attRecent],
            'exams' => $exams,
            'homework' => $homework,
            'observations' => $observations,
            'weak_topics' => $weak,
            'observation_options' => ['kinds' => StudentObservation::KINDS, 'categories' => StudentObservation::CATEGORIES],
            'can' => ['observe' => $request->user()->can('teacher_portal.observations')],
        ]);
    }

    // ------------------------------------------------------------------ gözlem / davranış puanı

    public function observationStore(Request $request, int $student): JsonResponse
    {
        $teacher = $this->teacher($request);
        $scope = $this->scope($request);
        $scope->assertStudent($student);

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(StudentObservation::KINDS))],
            'category' => ['required', Rule::in(array_keys(StudentObservation::CATEGORIES))],
            'points' => ['nullable', 'integer', 'min:-5', 'max:5'],
            'body' => ['required', 'string', 'min:3', 'max:1000'],
            'class_group_id' => ['nullable', 'integer'],
            'subject_id' => ['nullable', 'integer'],
            'visible_to_guardian' => ['boolean'],
            'visible_to_student' => ['boolean'],
        ], [
            'body.required' => 'Gözlem notunu yazın.',
            'body.min' => 'Gözlem notu en az :min karakter olmalı.',
        ], ['kind' => 'Gözlem türü', 'category' => 'Alan', 'body' => 'Gözlem notu', 'points' => 'Puan']);

        if (! empty($data['class_group_id'])) {
            $scope->assertGroup((int) $data['class_group_id']);
        }
        if (! empty($data['subject_id']) && ! $scope->subjectIds()->contains((int) $data['subject_id'])) {
            throw new BusinessRuleException('Bu ders sizin derslerinizden değil.', 'teacher_scope_subject', [], 422);
        }

        $points = (int) ($data['points'] ?? 0);
        // Puan yönü türle tutarlı olmalı (olumlu → negatif puan olmaz)
        if (($data['kind'] === 'positive' && $points < 0) || ($data['kind'] === 'improve' && $points > 0)) {
            throw new BusinessRuleException('Puan, gözlem türüyle uyumlu olmalı (olumlu → artı, gelişmeli → eksi).', 'observation_points_mismatch', [], 422);
        }

        $s = Student::query()->findOrFail($student);
        $obs = StudentObservation::query()->create([
            'branch_id' => $s->branch_id,
            'student_id' => $s->id,
            'teacher_id' => $teacher->id,
            'user_id' => $request->user()->id,
            'class_group_id' => $data['class_group_id'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'kind' => $data['kind'],
            'category' => $data['category'],
            'points' => $points,
            'body' => trim($data['body']),
            'visible_to_guardian' => (bool) ($data['visible_to_guardian'] ?? false),
            'visible_to_student' => (bool) ($data['visible_to_student'] ?? false),
        ]);

        Audit::log('student.observation_added', sprintf('%s öğrencisine gözlem notu ekledi (%s, %+d puan%s).', $s->full_name,
            StudentObservation::KINDS[$obs->kind], $points, $obs->visible_to_guardian ? ', veliye açık' : ''), $obs);

        return $this->isoJson(['message' => 'Gözlem notu kaydedildi.', 'id' => $obs->id], 201);
    }

    public function observationDestroy(Request $request, int $observation): JsonResponse
    {
        $teacher = $this->teacher($request);
        $obs = StudentObservation::query()->whereKey($observation)->where('teacher_id', $teacher->id)->firstOrFail();

        $obs->delete();
        Audit::log('student.observation_deleted', 'Gözlem notunu sildi.', $obs);

        return $this->ok('Gözlem notu silindi.');
    }

    /** Öğretmenin son gözlemleri (tüm öğrencileri). */
    public function observations(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);

        return $this->isoJson([
            'data' => $this->observationRows(StudentObservation::query()->where('teacher_id', $teacher->id), $teacher, 60, withStudent: true),
            'options' => ['kinds' => StudentObservation::KINDS, 'categories' => StudentObservation::CATEGORIES],
        ]);
    }

    private function observationRows($query, Teacher $teacher, int $limit = 40, bool $withStudent = false): Collection
    {
        return $query->with(['teacher:id,first_name,last_name', 'subject:id,name', 'classGroup:id,name', 'student:id,full_name,student_no'])
            ->latest('id')->limit($limit)->get()
            ->map(fn (StudentObservation $o) => [
                'id' => $o->id,
                'kind' => $o->kind,
                'kind_label' => StudentObservation::KINDS[$o->kind] ?? $o->kind,
                'category' => $o->category,
                'category_label' => StudentObservation::CATEGORIES[$o->category] ?? $o->category,
                'points' => $o->points,
                'body' => $o->body,
                'visible_to_guardian' => $o->visible_to_guardian,
                'visible_to_student' => $o->visible_to_student,
                'subject' => $o->subject?->name,
                'class_group' => $o->classGroup?->name,
                'teacher' => $o->teacher?->full_name,
                'is_mine' => $o->teacher_id === $teacher->id,
                'created_at' => $o->created_at?->toAtomString(),
                'student' => $withStudent && $o->student ? ['id' => $o->student->id, 'full_name' => $o->student->full_name, 'student_no' => $o->student->student_no] : null,
            ]);
    }

    // ------------------------------------------------------------------ veli talepleri

    public function requests(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);
        $status = $request->query('status', 'open');

        $rows = ContactRequest::query()->where('teacher_id', $teacher->id)
            ->when(in_array($status, array_keys(ContactRequest::STATUSES), true), fn ($q) => $q->where('status', $status))
            ->with(['student:id,full_name,student_no', 'guardian:id,first_name,last_name'])
            ->latest('id')->limit(100)->get()
            ->map(fn (ContactRequest $r) => $this->requestRow($r));

        $counts = ContactRequest::query()->where('teacher_id', $teacher->id)->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        return $this->isoJson(['data' => $rows, 'counts' => $counts]);
    }

    public function requestRespond(Request $request, int $contactRequest): JsonResponse
    {
        $teacher = $this->teacher($request);
        $row = ContactRequest::query()->whereKey($contactRequest)->where('teacher_id', $teacher->id)->firstOrFail();

        $data = $request->validate([
            'response' => ['nullable', 'string', 'max:2000', 'required_if:status,answered'],
            'status' => ['required', Rule::in(['answered', 'closed'])],
        ], ['response.required_if' => 'Yanıt metnini yazın.'], ['response' => 'Yanıt', 'status' => 'Durum']);

        $row->forceFill([
            'status' => $data['status'],
            'response' => $data['response'] ?? $row->response,
            'responded_by' => $request->user()->id,
            'responded_at' => now(),
        ])->save();

        Audit::log('contact_request.responded', sprintf('Veli talebini %s: "%s".', $data['status'] === 'answered' ? 'yanıtladı' : 'kapattı', mb_substr($row->subject, 0, 80)), $row);
        // Veliye yalnız uygulama içi bildirim (SMS/WhatsApp gönderilmez)
        app(\App\Services\Portal\ContactRequestNotifier::class)->responded($row, $teacher->full_name);

        return $this->isoJson(['message' => $data['status'] === 'answered' ? 'Yanıtınız veliye portalda gösterilecek.' : 'Talep kapatıldı.', 'data' => $this->requestRow($row->load(['student:id,full_name,student_no', 'guardian:id,first_name,last_name']))]);
    }

    private function requestRow(ContactRequest $r): array
    {
        return [
            'id' => $r->id,
            'kind' => $r->kind,
            'kind_label' => ContactRequest::KINDS[$r->kind] ?? $r->kind,
            'subject' => $r->subject,
            'body' => $r->body,
            'preferred_times' => $r->preferred_times,
            'status' => $r->status,
            'status_label' => ContactRequest::STATUSES[$r->status] ?? $r->status,
            'response' => $r->response,
            'responded_at' => $r->responded_at?->toAtomString(),
            'created_at' => $r->created_at?->toAtomString(),
            'student' => $r->student ? ['id' => $r->student->id, 'full_name' => $r->student->full_name, 'student_no' => $r->student->student_no] : null,
            'guardian' => $r->guardian ? trim($r->guardian->first_name.' '.$r->guardian->last_name) : null,
        ];
    }

    // ------------------------------------------------------------------ etüt, sınav, duyuru, profil

    public function study(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);
        $rows = $this->studyRows($teacher, now()->subDays(30), now()->addDays(45));

        $students = DB::table('study_session_student as sss')->join('students as st', 'st.id', '=', 'sss.student_id')
            ->whereIn('sss.study_session_id', $rows->pluck('id'))
            ->get(['sss.study_session_id', 'st.id', 'st.full_name', 'sss.attendance'])->groupBy('study_session_id');

        $rows = $rows->map(fn ($r) => [...(array) $r, 'students' => ($students[$r->id] ?? collect())->map(fn ($x) => ['id' => (int) $x->id, 'full_name' => $x->full_name, 'attendance' => $x->attendance])->values()]);

        $availability = DB::table('teacher_availabilities')->where('teacher_id', $teacher->id)->orderBy('weekday')->orderBy('starts_at')
            ->get(['weekday', 'starts_at', 'ends_at']);
        $leaves = DB::table('teacher_leaves')->where('teacher_id', $teacher->id)->where('ends_on', '>=', now()->subDays(30)->toDateString())
            ->orderBy('starts_on')->get();

        return $this->isoJson([
            'upcoming' => $rows->filter(fn ($r) => $r['starts_at'] >= now()->toDateTimeString())->values(),
            'past' => $rows->filter(fn ($r) => $r['starts_at'] < now()->toDateTimeString())->sortByDesc('starts_at')->values(),
            'availability' => $availability,
            'leaves' => $leaves->map(fn ($l) => collect((array) $l)->only(['id', 'kind', 'starts_on', 'ends_on', 'status', 'reason'])),
            'max_weekly_hours' => $teacher->max_weekly_hours,
            'target_weekly_hours' => $teacher->target_weekly_hours,
        ]);
    }

    public function exams(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);
        $scope = $this->scope($request);
        $groupIds = $scope->groupIds();
        $studentIds = $groupIds->isEmpty() ? collect() : DB::table('class_group_student')->whereIn('class_group_id', $groupIds)->whereNull('left_on')->pluck('student_id');

        $recent = DB::table('exams as e')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->where('e.branch_id', $teacher->branch_id)->where('e.status', 'results_published')->whereNull('e.deleted_at')
            ->orderByDesc('e.exam_date')->limit(8)
            ->get(['e.id', 'e.name', 'e.exam_date', 't.code as type', 'e.participant_count']);

        $mine = $studentIds->isEmpty() || $recent->isEmpty() ? collect() : DB::table('exam_results as r')
            ->join('class_group_student as cgs', fn ($j) => $j->on('cgs.student_id', '=', 'r.student_id')->whereNull('cgs.left_on'))
            ->join('class_groups as g', 'g.id', '=', 'cgs.class_group_id')
            ->whereIn('r.exam_id', $recent->pluck('id'))->whereIn('cgs.class_group_id', $groupIds)
            ->groupBy('r.exam_id', 'g.id', 'g.name')
            ->selectRaw('r.exam_id, g.id AS group_id, g.name AS group_name, COUNT(*) AS participants, AVG(r.net) AS avg_net, MAX(r.net) AS max_net')
            ->get()->groupBy('exam_id');
        $overall = $recent->isEmpty() ? collect() : DB::table('exam_results')->whereIn('exam_id', $recent->pluck('id'))
            ->groupBy('exam_id')->selectRaw('exam_id, AVG(net) AS avg_net')->pluck('avg_net', 'exam_id');

        return $this->isoJson([
            'upcoming' => $this->upcomingExams($teacher->branch_id, 60),
            'recent' => $recent->map(fn ($e) => [
                ...(array) $e,
                'institution_avg' => isset($overall[$e->id]) ? round((float) $overall[$e->id], 2) : null,
                'my_classes' => ($mine[$e->id] ?? collect())->map(fn ($m) => ['group_id' => (int) $m->group_id, 'name' => $m->group_name, 'participants' => (int) $m->participants, 'avg_net' => round((float) $m->avg_net, 2), 'max_net' => round((float) $m->max_net, 2)])->sortBy('name')->values(),
            ]),
        ]);
    }

    public function announcements(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);
        $rows = AnnouncementAudience::withReadState(AnnouncementAudience::forTeacher($teacher->branch_id)->limit(50), $request->user()->id);

        return $this->isoJson(['data' => $rows, 'unread' => $rows->where('is_read', false)->count()]);
    }

    public function announcementRead(Request $request, int $announcement): JsonResponse
    {
        $teacher = $this->teacher($request);
        $exists = AnnouncementAudience::forTeacher($teacher->branch_id)->where('id', $announcement)->exists();
        abort_unless($exists, 404);
        AnnouncementAudience::markRead($announcement, $request->user()->id);

        return $this->ok('Okundu olarak işaretlendi.');
    }

    public function profile(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);
        /** @var User $user */
        $user = $request->user();

        $subjects = DB::table('teacher_subject as ts')->join('subjects as s', 's.id', '=', 'ts.subject_id')
            ->where('ts.teacher_id', $teacher->id)->orderBy('s.name')->pluck('s.name');

        $institution = \App\Support\Settings::group('institution', $teacher->branch_id);

        return $this->isoJson([
            'teacher' => [
                ...$this->identity($teacher),
                'phone' => $teacher->phone,
                'email' => $teacher->email,
                'hired_on' => $teacher->hired_on?->toDateString(),
                'employment_type_label' => Teacher::EMPLOYMENT_TYPES[$teacher->employment_type] ?? $teacher->employment_type,
                'subjects' => $subjects,
            ],
            'institution' => ['name' => $institution['name'], 'phone' => $institution['phone'], 'email' => $institution['email'], 'address' => $institution['address']],
            'account' => [
                'username' => $user->username,
                'last_login_at' => $user->last_login_at?->toAtomString(),
                'password_changed_at' => $user->password_changed_at?->toAtomString(),
                'uses_initial_password' => ($user->getAttributes()['initial_password'] ?? null) !== null,
            ],
            'permissions' => [
                'attendance' => $user->can('teacher_portal.attendance'),
                'homework' => $user->can('teacher_portal.homework'),
                'observations' => $user->can('teacher_portal.observations'),
            ],
        ]);
    }

    // ------------------------------------------------------------------ yardımcılar

    protected function identity(Teacher $teacher): array
    {
        return [
            'id' => $teacher->id,
            'full_name' => $teacher->full_name,
            'first_name' => $teacher->first_name,
            'title' => $teacher->title,
            'specialty' => $teacher->specialty,
            'color' => $teacher->color,
            'avatar_url' => $teacher->avatar_path ? Storage::disk('public')->url($teacher->avatar_path) : null,
        ];
    }

    /** Öğretmenin ders oturumları (+ yoklama sayıları). */
    protected function sessions(Teacher $teacher, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $rows = DB::table('lesson_sessions as ls')
            ->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->leftJoin('classrooms as c', 'c.id', '=', 'ls.classroom_id')
            ->leftJoin('class_groups as g', 'g.id', '=', 'ls.class_group_id')
            ->where('ls.teacher_id', $teacher->id)
            ->whereBetween('ls.date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('ls.starts_at')
            ->get(['ls.id', 'ls.date', 'ls.starts_at', 'ls.ends_at', 'ls.status', 'ls.cancel_reason', 'ls.topic_note', 'ls.class_group_id',
                's.name as subject', 's.color as subject_color', 'c.name as classroom', 'g.name as class_group', 'ls.attendance_taken_at']);

        if ($rows->isEmpty()) {
            return $rows;
        }

        $roster = DB::table('class_group_student')->whereIn('class_group_id', $rows->pluck('class_group_id')->unique())->whereNull('left_on')
            ->selectRaw('class_group_id, COUNT(*) AS c')->groupBy('class_group_id')->pluck('c', 'class_group_id');
        $taken = DB::table('attendances')->whereIn('lesson_session_id', $rows->pluck('id'))
            ->groupBy('lesson_session_id')->selectRaw("lesson_session_id, SUM(status='absent') AS absent, SUM(status='late') AS late, COUNT(*) AS total")
            ->get()->keyBy('lesson_session_id');

        return $rows->map(function ($r) use ($roster, $taken) {
            $t = $taken[$r->id] ?? null;
            $r->date = substr((string) $r->date, 0, 10);
            $r->roster = (int) ($roster[$r->class_group_id] ?? 0);
            $r->absent = (int) ($t->absent ?? 0);
            $r->late = (int) ($t->late ?? 0);
            $r->recorded = (int) ($t->total ?? 0);
            $r->can_take_attendance = self::attendanceWindowOpen($r->status, $r->date, (string) $r->starts_at);

            return $r;
        });
    }

    /** Yoklama alınabilir mi: iptal değil, dersin başlamasına ≤15 dk kalmış, en fazla 7 gün geçmiş. */
    public static function attendanceWindowOpen(string $status, string $date, string $startsAt, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        if ($status === 'cancelled') {
            return false;
        }
        $start = CarbonImmutable::parse($startsAt);
        if ($start->subMinutes(15)->gt($now)) {
            return false;
        }

        return CarbonImmutable::parse($date)->startOfDay()->gte($now->startOfDay()->subDays(self::ATTENDANCE_EDIT_DAYS));
    }

    /** Son 7 günde başlamış ama yoklaması alınmamış dersler. */
    protected function pendingAttendance(Teacher $teacher): Collection
    {
        return DB::table('lesson_sessions as ls')->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->leftJoin('class_groups as g', 'g.id', '=', 'ls.class_group_id')
            ->where('ls.teacher_id', $teacher->id)->where('ls.status', '!=', 'cancelled')->whereNull('ls.attendance_taken_at')
            ->where('ls.date', '>=', now()->subDays(self::ATTENDANCE_EDIT_DAYS)->toDateString())
            ->where('ls.starts_at', '<=', now()->addMinutes(15))
            ->orderByDesc('ls.starts_at')->limit(10)
            ->get(['ls.id', 'ls.date', 'ls.starts_at', 'ls.ends_at', 's.name as subject', 'g.name as class_group']);
    }

    protected function studyRows(Teacher $teacher, $from, $to): Collection
    {
        return DB::table('study_sessions as ss')
            ->leftJoin('subjects as s', 's.id', '=', 'ss.subject_id')->leftJoin('classrooms as c', 'c.id', '=', 'ss.classroom_id')
            ->leftJoin('study_session_student as sss', 'sss.study_session_id', '=', 'ss.id')
            ->where('ss.teacher_id', $teacher->id)->whereNotIn('ss.status', ['cancelled', 'rejected'])
            ->whereBetween('ss.starts_at', [$from, $to])
            ->groupBy('ss.id', 'ss.kind', 'ss.starts_at', 'ss.ends_at', 'ss.topic', 'ss.status', 'ss.capacity', 's.name', 'c.name')
            ->orderBy('ss.starts_at')
            ->get(['ss.id', 'ss.kind', 'ss.starts_at', 'ss.ends_at', 'ss.topic', 'ss.status', 'ss.capacity', 's.name as subject', 'c.name as classroom', DB::raw('COUNT(sss.id) AS student_count')]);
    }

    protected function upcomingExams(int $branchId, int $days): Collection
    {
        return DB::table('exams as e')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->where('e.branch_id', $branchId)->whereNull('e.deleted_at')->where('e.status', '!=', 'cancelled')
            ->whereBetween('e.exam_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->orderBy('e.exam_date')
            ->get(['e.id', 'e.name', 'e.exam_date', 'e.publisher', 't.code as type', 't.name as type_name']);
    }

    /** @return array<int, string> öğrenci id → son yayımlanmış sınav neti */
    protected function lastNets(Collection $studentIds): array
    {
        if ($studentIds->isEmpty()) {
            return [];
        }
        $rows = DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')
            ->whereIn('r.student_id', $studentIds)->where('e.status', 'results_published')->whereNull('e.deleted_at')
            ->orderByDesc('e.exam_date')->orderByDesc('e.id')
            ->get(['r.student_id', 'r.net', 'e.name']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->student_id] ??= $r->net;
        }

        return $out;
    }
}
