<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Exams\ExamResultController;
use App\Http\Middleware\EnsurePortalStudent;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Guardian;
use App\Models\GuidanceMeeting;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentObservation;
use App\Models\User;
use App\Services\Auth\Impersonation;
use App\Services\Exams\ReportCardRenderer;
use App\Services\Portal\AnnouncementAudience;
use App\Support\Sensitive;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Öğrenci ve veli portalı (aynı uçlar, rol farkıyla). HER uç yalnız oturumdaki öğrencinin ya da
 * velinin BAĞLI öğrencisinin verisini döner: öğrenci kaydı EnsurePortalStudent ara katmanında çözülür
 * (veli için `student_id` yalnız kendi çocukları arasından kabul edilir).
 * Gizli rehberlik notları, risk puanı, iç notlar ve personel bilgileri asla dönmez.
 * Rehberlik: öğrenci yalnız `visible_to_student`, veli yalnız `visibility = guardian` görüşmeleri görür.
 */
class PortalController extends ApiController
{
    public const ATTENDANCE_LABELS = ['present' => 'Geldi', 'late' => 'Geç kaldı', 'absent' => 'Gelmedi', 'excused' => 'İzinli', 'medical' => 'Raporlu'];

    public const HOMEWORK_LABELS = ['assigned' => 'Yapılacak', 'seen' => 'Görüldü', 'submitted' => 'Teslim edildi', 'late' => 'Geç teslim', 'missed' => 'Teslim edilmedi'];

    public const INSTALLMENT_LABELS = ['pending' => 'Bekliyor', 'partial' => 'Kısmen ödendi', 'paid' => 'Ödendi', 'overdue' => 'Gecikmiş', 'cancelled' => 'İptal'];

    private function student(Request $request): Student
    {
        return $request->attributes->get(EnsurePortalStudent::ATTRIBUTE);
    }

    private function guardian(Request $request): ?Guardian
    {
        return EnsurePortalStudent::isGuardianRequest($request) ? $request->attributes->get(EnsurePortalStudent::GUARDIAN_ATTRIBUTE) : null;
    }

    /** Portal bağlamı: rol, (veli için) görüntülenebilen öğrenciler ve seçili öğrenci. */
    public function context(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $guardian = $this->guardian($request);
        /** @var Collection<int, Student> $children */
        $children = $request->attributes->get(EnsurePortalStudent::CHILDREN_ATTRIBUTE, collect([$student]));

        $groups = DB::table('class_group_student as cgs')->join('class_groups as g', 'g.id', '=', 'cgs.class_group_id')
            ->whereIn('cgs.student_id', $children->pluck('id'))->whereNull('cgs.left_on')->whereNull('g.deleted_at')
            ->get(['cgs.student_id', 'g.name'])->groupBy('student_id');

        return $this->json([
            'role' => $guardian ? 'guardian' : 'student',
            'guardian' => $guardian ? ['full_name' => $guardian->full_name, 'first_name' => $guardian->first_name, 'last_name' => $guardian->last_name] : null,
            'selected_student_id' => $student->id,
            'students' => $children->map(fn (Student $c) => [
                'id' => $c->id,
                'full_name' => $c->full_name,
                'first_name' => $c->first_name,
                'student_no' => $c->student_no,
                'photo_url' => $c->photo_path ? Storage::disk('public')->url($c->photo_path) : null,
                'class_groups' => ($groups[$c->id] ?? collect())->pluck('name')->values(),
                'relationship' => $guardian ? $c->pivot?->relationship : null,
                'is_primary' => $guardian ? (bool) $c->pivot?->is_primary : null,
                'status_label' => Student::STATUSES[$c->status] ?? $c->status,
            ])->values(),
        ]);
    }

    /** @return Collection<int, int> */
    private function groupIds(Student $student): Collection
    {
        return DB::table('class_group_student')->where('student_id', $student->id)->whereNull('left_on')->pluck('class_group_id');
    }

    public function summary(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $today = CarbonImmutable::today();
        $groupIds = $this->groupIds($student);

        $lessons = $this->sessionsBetween($student, $groupIds, $today, $today);

        $presence = DB::table('daily_presences')->where('student_id', $student->id)->where('date', $today->toDateString())
            ->first(['first_entry_at', 'last_exit_at', 'is_inside', 'minutes_inside']);

        $lastAttendance = DB::table('attendances as a')->join('lesson_sessions as ls', 'ls.id', '=', 'a.lesson_session_id')
            ->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->where('a.student_id', $student->id)->orderByDesc('ls.starts_at')
            ->first(['a.date', 'a.status', 'a.late_minutes', 'ls.starts_at', 's.name as subject']);

        $att30 = DB::table('attendances')->where('student_id', $student->id)->where('date', '>=', $today->subDays(30)->toDateString())
            ->selectRaw("COUNT(*) AS total, SUM(status IN ('present','late')) AS attended, SUM(status='absent') AS absent, SUM(status='late') AS late")->first();

        $exams = $this->examRows($student)->take(2)->values();

        $homework = $this->homeworkQuery($student)
            ->whereIn('hs.status', ['assigned', 'seen'])->where('h.due_at', '>', now())
            ->orderBy('h.due_at')->limit(3)->get(['h.id', 'h.title', 'h.due_at', 's.name as subject']);
        $homeworkOpen = $this->homeworkQuery($student)->whereIn('hs.status', ['assigned', 'seen'])->where('h.due_at', '>', now())->count();

        return $this->json([
            'student' => $this->identity($student),
            'today' => [
                'date' => $today->toDateString(),
                'lessons' => $lessons,
                'presence' => $presence,
            ],
            'attendance' => [
                'last' => $lastAttendance ? [...(array) $lastAttendance, 'status_label' => self::ATTENDANCE_LABELS[$lastAttendance->status] ?? $lastAttendance->status] : null,
                'total_30' => (int) ($att30->total ?? 0),
                'attended_30' => (int) ($att30->attended ?? 0),
                'absent_30' => (int) ($att30->absent ?? 0),
                'late_30' => (int) ($att30->late ?? 0),
            ],
            'last_exam' => $exams->first(),
            'previous_exam_net' => $exams->get(1)?->net,
            'homework' => ['open_count' => $homeworkOpen, 'next' => $homework],
            'finance' => $this->financeTotals($student),
            'announcements' => AnnouncementAudience::withReadState($this->announcementQuery($request, $student, $groupIds)->limit(3), $request->user()->id),
            'unread_announcements' => AnnouncementAudience::unreadCount($this->announcementQuery($request, $student, $groupIds), $request->user()->id),
            'upcoming_exams' => DB::table('exams as e')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')
                ->where('e.branch_id', $student->branch_id)->whereNull('e.deleted_at')->where('e.status', '!=', 'cancelled')
                ->whereBetween('e.exam_date', [$today->toDateString(), $today->addDays(21)->toDateString()])
                ->orderBy('e.exam_date')->limit(3)->get(['e.id', 'e.name', 'e.exam_date', 't.code as type']),
            'next_study' => DB::table('study_session_student as sss')->join('study_sessions as ss', 'ss.id', '=', 'sss.study_session_id')
                ->leftJoin('subjects as sub', 'sub.id', '=', 'ss.subject_id')
                ->where('sss.student_id', $student->id)->whereIn('ss.status', ['approved', 'requested'])->where('ss.starts_at', '>=', now())
                ->orderBy('ss.starts_at')->first(['ss.id', 'ss.kind', 'ss.starts_at', 'ss.status', 'sub.name as subject']),
            'feedback' => StudentObservation::query()->where('student_id', $student->id)
                ->where($this->guardian($request) ? 'visible_to_guardian' : 'visible_to_student', true)
                ->with('teacher:id,first_name,last_name')->latest('id')->limit(2)->get()
                ->map(fn (StudentObservation $o) => ['id' => $o->id, 'kind' => $o->kind, 'points' => $o->points, 'body' => $o->body, 'teacher' => $o->teacher?->full_name, 'created_at' => $o->created_at?->toAtomString()]),
        ]);
    }

    /** Haftalık ders programı (?date=YYYY-MM-DD haftası; varsayılan bu hafta) + etüt/birebir. */
    public function schedule(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $ref = $this->parseDate($request->query('date')) ?? CarbonImmutable::today();
        $start = $ref->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->addDays(6);

        $sessions = $this->sessionsBetween($student, $this->groupIds($student), $start, $end);

        $study = DB::table('study_session_student as sss')->join('study_sessions as ss', 'ss.id', '=', 'sss.study_session_id')
            ->leftJoin('subjects as s', 's.id', '=', 'ss.subject_id')->leftJoin('classrooms as c', 'c.id', '=', 'ss.classroom_id')
            ->join('teachers as t', 't.id', '=', 'ss.teacher_id')
            ->where('sss.student_id', $student->id)->whereIn('ss.status', ['approved', 'completed'])
            ->whereBetween('ss.starts_at', [$start->startOfDay(), $end->endOfDay()])
            ->orderBy('ss.starts_at')
            ->get(['ss.id', 'ss.kind', 'ss.starts_at', 'ss.ends_at', 'ss.topic', 'ss.status', 's.name as subject', 'c.name as classroom',
                DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")]);

        $days = [];
        for ($d = $start; $d <= $end; $d = $d->addDay()) {
            $key = $d->toDateString();
            $days[] = [
                'date' => $key,
                'lessons' => $sessions->filter(fn ($s) => $s->date === $key)->values(),
                'study' => $study->filter(fn ($s) => substr((string) $s->starts_at, 0, 10) === $key)->values(),
            ];
        }

        return $this->json([
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'class_groups' => $this->classGroups($student),
            'days' => $days,
        ]);
    }

    public function attendance(Request $request): JsonResponse
    {
        $student = $this->student($request);

        $rows = DB::table('attendances as a')->join('lesson_sessions as ls', 'ls.id', '=', 'a.lesson_session_id')
            ->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->where('a.student_id', $student->id)
            ->when(in_array($request->query('status'), array_keys(self::ATTENDANCE_LABELS), true), fn ($q) => $q->where('a.status', $request->query('status')))
            ->orderByDesc('ls.starts_at')
            ->paginate($this->perPage($request, 30), ['a.id', 'a.date', 'a.status', 'a.late_minutes', 'ls.starts_at', 'ls.ends_at', 's.name as subject']);

        $summary = DB::table('attendances')->where('student_id', $student->id)
            ->selectRaw("COUNT(*) AS total, SUM(status='present') AS present, SUM(status='late') AS late, SUM(status='absent') AS absent, SUM(status='excused') AS excused, SUM(status='medical') AS medical")->first();

        $bySubject = DB::table('attendances as a')->join('lesson_sessions as ls', 'ls.id', '=', 'a.lesson_session_id')
            ->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->where('a.student_id', $student->id)
            ->groupBy('s.id', 's.name')
            ->selectRaw("s.name AS subject, COUNT(*) AS total, SUM(a.status='absent') AS absent, SUM(a.status='late') AS late")
            ->orderByDesc('absent')->get();

        $presences = DB::table('daily_presences')->where('student_id', $student->id)->orderByDesc('date')->limit(14)
            ->get(['date', 'first_entry_at', 'last_exit_at', 'minutes_inside', 'is_inside']);

        return $this->json($this->paginated($rows, fn ($r) => [...(array) $r, 'status_label' => self::ATTENDANCE_LABELS[$r->status] ?? $r->status], [
            'summary' => array_map('intval', (array) $summary),
            'by_subject' => $bySubject,
            'presences' => $presences,
        ])->getData(true));
    }

    public function exams(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $goal = DB::table('student_goals')->where('student_id', $student->id)->where('is_active', true)->latest('id')
            ->first(['target_tyt_net', 'target_ayt_net']);

        return $this->json([
            'data' => $this->examRows($student, 100),
            'goal' => $goal,
        ]);
    }

    /** Kendi sınav sonucunun PDF belgesi (yalnız yayımlanmış sınav, yalnız kendi sonucu). */
    public function examPdf(Request $request, int $result): Response
    {
        $student = $this->student($request);

        $row = ExamResult::query()->whereKey($result)->where('student_id', $student->id)->firstOrFail();
        $exam = Exam::query()->whereKey($row->exam_id)->where('status', 'results_published')->firstOrFail();

        return app(ExamResultController::class)->pdf($exam, $row, app(ReportCardRenderer::class));
    }

    public function homework(Request $request): JsonResponse
    {
        $student = $this->student($request);

        $rows = $this->homeworkQuery($student)
            ->leftJoin('teachers as t', 't.id', '=', 'h.teacher_id')
            ->orderByRaw("CASE WHEN hs.status IN ('assigned','seen') AND h.due_at > NOW() THEN 0 ELSE 1 END")
            ->orderByDesc('h.due_at')
            ->limit(200)
            ->get(['hs.id', 'h.title', 'h.description', 'h.assigned_at', 'h.due_at', 'hs.status', 'hs.submitted_at', 'hs.score', 'hs.teacher_note', 'hs.graded_at',
                DB::raw("(hs.answer_text IS NOT NULL OR EXISTS (SELECT 1 FROM documents d WHERE d.documentable_type = 'homework_submission' AND d.documentable_id = hs.id AND d.deleted_at IS NULL)) AS has_submission"),
                's.name as subject', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")])
            ->map(fn ($r) => [
                ...(array) $r,
                'status_label' => self::HOMEWORK_LABELS[$r->status] ?? $r->status,
                'is_open' => in_array($r->status, ['assigned', 'seen'], true) && strtotime((string) $r->due_at) > time(),
                'has_submission' => (bool) $r->has_submission,
                'is_graded' => $r->score !== null || $r->graded_at !== null,
            ]);

        return $this->json([
            'data' => $rows,
            'summary' => [
                'total' => $rows->count(),
                'open' => $rows->where('is_open', true)->count(),
                'done' => $rows->whereIn('status', ['submitted', 'late'])->count(),
                'missed' => $rows->where('status', 'missed')->count(),
            ],
        ]);
    }

    public function finance(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $guardian = $this->guardian($request);

        $enrollments = DB::table('enrollments as e')->join('programs as p', 'p.id', '=', 'e.program_id')
            ->leftJoin('academic_terms as at', 'at.id', '=', 'e.academic_term_id')
            ->where('e.student_id', $student->id)->whereNull('e.deleted_at')
            ->orderByDesc('e.enrolled_on')
            ->get(['e.id', 'e.enrollment_no', 'p.name as program', 'at.name as term', 'e.status', 'e.enrolled_on', 'e.list_price',
                DB::raw('(e.discount_amount + e.scholarship_amount) AS discount'), 'e.net_price', 'e.financial_guardian_id']);

        // Veli: finansal sorumlusu olduğu kayıtlar işaretlenir (kayıtta açıkça seçilmişse o; seçilmemişse veli bağındaki "finansal sorumlu")
        $pivotResponsible = $guardian ? (bool) DB::table('guardian_student')->where('guardian_id', $guardian->id)->where('student_id', $student->id)->value('is_financially_responsible') : false;
        $enrollments = $enrollments->map(function ($e) use ($guardian, $pivotResponsible) {
            $row = collect((array) $e)->except('financial_guardian_id')->all();
            if ($guardian) {
                $row['is_my_responsibility'] = $e->financial_guardian_id ? (int) $e->financial_guardian_id === $guardian->id : $pivotResponsible;
            }

            return $row;
        });

        $installments = DB::table('installments')->where('student_id', $student->id)->where('status', '!=', 'cancelled')
            ->orderBy('due_date')->orderBy('sequence')
            ->get(['id', 'enrollment_id', 'sequence', 'due_date', 'amount', 'paid_amount', 'status', 'paid_at'])
            ->map(fn ($i) => [
                ...(array) $i,
                'remaining' => bcsub((string) $i->amount, (string) $i->paid_amount, 2),
                'status_label' => self::INSTALLMENT_LABELS[$i->status] ?? $i->status,
            ]);

        $payments = DB::table('payments')->where('student_id', $student->id)
            ->orderByDesc('paid_at')
            ->get(['id', 'receipt_no', 'amount', 'method', 'paid_at', 'voided_at'])
            ->map(fn ($p) => [...(array) $p, 'method_label' => Payment::METHODS[$p->method] ?? $p->method, 'is_voided' => $p->voided_at !== null]);

        // Veli: kardeşlerin toplu borç özeti (yalnız kendi bağlı öğrencileri)
        $household = null;
        if ($guardian) {
            $children = $request->attributes->get(EnsurePortalStudent::CHILDREN_ATTRIBUTE, collect());
            if ($children->count() > 1) {
                $household = $children->map(fn (Student $c) => ['student_id' => $c->id, 'full_name' => $c->full_name] + array_intersect_key($this->financeTotals($c), array_flip(['total', 'paid', 'remaining', 'overdue', 'overdue_count'])))->values();
            }
        }

        return $this->json([
            'totals' => $this->financeTotals($student),
            'enrollments' => $enrollments,
            'installments' => $installments,
            'payments' => $payments,
            'household' => $household,
        ]);
    }

    /**
     * Hedefler + paylaşılan görüşme özetleri (gizli not ve personel-içi kayıt ASLA).
     * Öğrenci: "öğrenci portalında göster" işaretli (ve "yalnız rehber" olmayan) görüşmeler.
     * Veli: "veli de görebilir" görünürlüklü görüşmeler.
     */
    public function guidance(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $isGuardian = (bool) $this->guardian($request);

        $goals = DB::table('student_goals')->where('student_id', $student->id)->where('is_active', true)->orderByDesc('id')
            ->get(['id', 'university', 'department', 'target_rank', 'target_tyt_net', 'target_ayt_net', 'subject_targets', 'updated_at'])
            ->map(fn ($g) => [...(array) $g, 'subject_targets' => $g->subject_targets ? json_decode($g->subject_targets, true) : null]);

        $meetings = GuidanceMeeting::query()
            ->where('student_id', $student->id)
            ->when($isGuardian,
                fn ($q) => $q->where('visibility', 'guardian'),
                fn ($q) => $q->where('visible_to_student', true)->whereIn('visibility', ['staff', 'guardian']))
            ->with('counselor:id,name')
            ->orderByDesc('met_at')->limit(50)
            ->get(['id', 'counselor_id', 'met_at', 'kind', 'summary', 'goal', 'next_meeting_on'])
            ->map(fn (GuidanceMeeting $m) => [
                'id' => $m->id,
                'met_at' => $m->met_at?->toAtomString(),
                'kind' => $m->kind,
                'kind_label' => ['individual' => 'Yüz yüze görüşme', 'phone' => 'Telefon görüşmesi', 'guardian' => 'Veli görüşmesi', 'group' => 'Grup görüşmesi', 'online' => 'Çevrim içi görüşme'][$m->kind] ?? 'Görüşme',
                'summary' => $m->summary,
                'goal' => $m->goal,
                'next_meeting_on' => $m->next_meeting_on?->toDateString(),
                'counselor' => $m->counselor?->name,
            ]);

        $teacher = $student->guidance_teacher_id
            ? DB::table('teachers')->where('id', $student->guidance_teacher_id)->first(['first_name', 'last_name', 'title'])
            : null;

        return $this->json([
            'counselor' => $teacher ? trim(($teacher->title ? $teacher->title.' ' : '').$teacher->first_name.' '.$teacher->last_name) : null,
            'target' => ['university' => $student->target_university, 'department' => $student->target_department],
            'goals' => $goals,
            'meetings' => $meetings,
        ]);
    }

    public function announcements(Request $request): JsonResponse
    {
        $student = $this->student($request);

        $rows = AnnouncementAudience::withReadState($this->announcementQuery($request, $student, $this->groupIds($student))->limit(50), $request->user()->id);

        return $this->json([
            'data' => $rows,
            'unread' => $rows->where('is_read', false)->count(),
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        $student = $this->student($request);
        /** @var User $user */
        $user = $request->user();

        // Önizlemede (personel) hassas veri görme yetkisi yoksa telefonlar maskelenir.
        $mask = false;
        if ($imp = Impersonation::activeFor($request)) {
            $staff = User::query()->find($imp['impersonator_id']);
            $mask = ! $staff || ! $staff->can('students.view_sensitive');
        }
        $phone = fn (?string $p) => $mask ? Sensitive::maskPhone($p) : $p;

        $guardian = $this->guardian($request);

        // Veli görünümünde öğrencinin DİĞER velilerinin bilgisi gösterilmez (ayrı yaşayan aileler vb.)
        $guardians = $guardian ? collect() : $student->guardians()->get()->map(fn ($g) => [
            'name' => $g->full_name,
            'relationship' => $g->pivot->relationship,
            'is_primary' => (bool) $g->pivot->is_primary,
            'phone' => $phone($g->phone),
        ]);

        return $this->json([
            'student' => [
                ...$this->identity($student),
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'birth_date' => $student->birth_date?->toDateString(),
                'school_name' => $student->school_name,
                'school_grade' => $student->school_grade,
                'field' => $student->field,
                'target_university' => $student->target_university,
                'target_department' => $student->target_department,
                'phone' => $phone($student->phone),
                'email' => $student->email,
                'registered_on' => $student->registered_on?->toDateString(),
                'status_label' => Student::STATUSES[$student->status] ?? $student->status,
            ],
            'guardians' => $guardians,
            'guardian' => $guardian ? [
                'full_name' => $guardian->full_name,
                'phone' => $phone($guardian->phone),
                'whatsapp_phone' => $phone($guardian->whatsapp_phone),
                'email' => $guardian->email,
                'occupation' => $guardian->occupation,
                'relationship' => DB::table('guardian_student')->where('guardian_id', $guardian->id)->where('student_id', $student->id)->value('relationship'),
            ] : null,
            'account' => [
                'username' => $user->username,
                'last_login_at' => $user->last_login_at?->toAtomString(),
                'password_changed_at' => $user->password_changed_at?->toAtomString(),
                'uses_initial_password' => ($user->getAttributes()['initial_password'] ?? null) !== null,
            ],
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * JSON yanıtı; "2026-09-16 18:30:00" biçimli tarih-saatler ISO'ya ("2026-09-16T18:30:00") çevrilir
     * (iOS Safari boşluklu biçimi çözemiyor).
     */
    private function json(mixed $payload): JsonResponse
    {
        $data = json_decode(json_encode($payload), true);
        array_walk_recursive($data, function (&$v) {
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) {
                $v = str_replace(' ', 'T', $v);
            }
        });

        return response()->json($data);
    }

    private function identity(Student $student): array
    {
        $counselor = $student->guidance_teacher_id
            ? DB::table('teachers')->where('id', $student->guidance_teacher_id)->selectRaw("CONCAT(first_name,' ',last_name) AS name")->value('name')
            : null;

        return [
            'full_name' => $student->full_name,
            'first_name' => $student->first_name,
            'student_no' => $student->student_no,
            'photo_url' => $student->photo_path ? Storage::disk('public')->url($student->photo_path) : null,
            'class_groups' => $this->classGroups($student),
            'counselor' => $counselor,
        ];
    }

    private function classGroups(Student $student): Collection
    {
        return DB::table('class_group_student as cgs')->join('class_groups as g', 'g.id', '=', 'cgs.class_group_id')
            ->leftJoin('programs as p', 'p.id', '=', 'g.program_id')
            ->leftJoin('classrooms as c', 'c.id', '=', 'g.homeroom_classroom_id')
            ->leftJoin('teachers as t', 't.id', '=', 'g.advisor_teacher_id')
            ->where('cgs.student_id', $student->id)->whereNull('cgs.left_on')->whereNull('g.deleted_at')
            ->get(['g.id', 'g.name', 'p.name as program', 'c.name as classroom', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS advisor")]);
    }

    private function sessionsBetween(Student $student, Collection $groupIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if ($groupIds->isEmpty()) {
            return collect();
        }

        return DB::table('lesson_sessions as ls')
            ->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->leftJoin('classrooms as c', 'c.id', '=', 'ls.classroom_id')
            ->leftJoin('teachers as t', 't.id', '=', 'ls.teacher_id')
            ->leftJoin('class_groups as g', 'g.id', '=', 'ls.class_group_id')
            ->leftJoin('attendances as a', fn ($j) => $j->on('a.lesson_session_id', '=', 'ls.id')->where('a.student_id', '=', $student->id))
            ->whereIn('ls.class_group_id', $groupIds)
            ->whereBetween('ls.date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('ls.starts_at')
            ->get(['ls.id', 'ls.date', 'ls.starts_at', 'ls.ends_at', 'ls.status', 'ls.cancel_reason', 'ls.topic_note', 's.name as subject', 's.color as subject_color',
                'c.name as classroom', 'g.name as class_group', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher"), 'a.status as attendance'])
            ->map(function ($r) {
                $r->attendance_label = $r->attendance ? (self::ATTENDANCE_LABELS[$r->attendance] ?? $r->attendance) : null;

                return $r;
            });
    }

    private function examRows(Student $student, int $limit = 30): Collection
    {
        $rows = DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->where('r.student_id', $student->id)->where('e.status', 'results_published')->whereNull('e.deleted_at')
            ->orderByDesc('e.exam_date')->orderByDesc('e.id')->limit($limit)
            ->get(['r.id', 'e.name', 'e.exam_date', 'e.publisher', 't.code as type', 't.name as type_name', 'r.net', 'r.score', 'r.correct', 'r.wrong', 'r.blank',
                'r.institution_rank', 'r.class_rank', 'e.participant_count']);

        $sections = DB::table('exam_result_sections as s')->join('exam_sections as es', 'es.id', '=', 's.exam_section_id')
            ->whereIn('s.exam_result_id', $rows->pluck('id'))->orderBy('es.sort')
            ->get(['s.exam_result_id', 'es.code', 'es.name', 's.net', 's.correct', 's.wrong', 's.blank'])->groupBy('exam_result_id');

        return $rows->map(function ($r) use ($sections) {
            $r->sections = ($sections[$r->id] ?? collect())->map(fn ($s) => collect((array) $s)->except('exam_result_id'))->values();

            return $r;
        });
    }

    private function homeworkQuery(Student $student)
    {
        return DB::table('homework_submissions as hs')->join('homework as h', 'h.id', '=', 'hs.homework_id')
            ->join('subjects as s', 's.id', '=', 'h.subject_id')
            ->whereNull('h.deleted_at')->where('hs.student_id', $student->id);
    }

    private function financeTotals(Student $student): array
    {
        $inst = DB::table('installments')->where('student_id', $student->id)->where('status', '!=', 'cancelled')
            ->selectRaw("COALESCE(SUM(amount),0) AS total, COALESCE(SUM(paid_amount),0) AS paid, COALESCE(SUM(amount - paid_amount),0) AS remaining,
                COALESCE(SUM(CASE WHEN status='overdue' THEN amount - paid_amount ELSE 0 END),0) AS overdue, COALESCE(SUM(status='overdue'),0) AS overdue_count")->first();
        $next = DB::table('installments')->where('student_id', $student->id)->whereIn('status', ['pending', 'partial', 'overdue'])
            ->orderBy('due_date')->first(['sequence', 'due_date', 'amount', 'paid_amount', 'status']);

        return [
            'total' => (string) $inst->total,
            'paid' => (string) $inst->paid,
            'remaining' => (string) $inst->remaining,
            'overdue' => (string) $inst->overdue,
            'overdue_count' => (int) $inst->overdue_count,
            'next_installment' => $next ? [
                'sequence' => $next->sequence, 'due_date' => $next->due_date, 'status' => $next->status,
                'remaining' => bcsub((string) $next->amount, (string) $next->paid_amount, 2),
            ] : null,
        ];
    }

    /**
     * Yayımlanmış duyurular. Öğrenci: tüm öğrenciler / kendi sınıfı / kendi programı.
     * Veli: velilere yönelik duyurular + seçili çocuğunun sınıf/program duyuruları ("yalnız öğrencilere" işaretliler hariç).
     */
    private function announcementQuery(Request $request, Student $student, Collection $groupIds)
    {
        $programIds = $groupIds->isEmpty() ? collect() : DB::table('class_groups')->whereIn('id', $groupIds)->pluck('program_id')->filter()->unique()->values();

        if ($this->guardian($request)) {
            return AnnouncementAudience::forGuardian($student->branch_id, $groupIds, $programIds);
        }

        return AnnouncementAudience::forStudent($student->branch_id, $groupIds, $programIds);
    }

    /** @return array{0: Collection<int,int>, 1: Collection<int,int>} öğrencinin açık sınıf ve program id'leri */
    public static function groupAndProgramIds(Student $student): array
    {
        $groupIds = DB::table('class_group_student')->where('student_id', $student->id)->whereNull('left_on')->pluck('class_group_id');
        $programIds = $groupIds->isEmpty() ? collect() : DB::table('class_groups')->whereIn('id', $groupIds)->pluck('program_id')->unique()->values();

        return [$groupIds, $programIds];
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
