<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Models\Installment;
use App\Models\Student;
use App\Models\Tag;
use App\Services\Finance\EnrollmentService;
use App\Services\Students\StudentInsights;
use App\Services\Students\StudentService;
use App\Support\Audit;
use App\Support\Sensitive;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends ApiController
{
    public function __construct(private readonly StudentService $students) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request);
        $this->applySort($query, $request, [
            'full_name' => 'full_name', 'student_no' => 'student_no', 'registered_on' => 'registered_on',
            'created_at' => 'created_at', 'open_balance' => 'open_balance', 'risk' => 'risk_score',
            'attendance' => 'attendance_30', 'last_exam' => 'last_exam_net',
        ], 'full_name');

        $user = $request->user();
        $counts = $this->baseFilters(Student::query(), $request, ignoreStatus: true)
            ->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        return $this->paginated($query->paginate($this->perPage($request)), fn (Student $s) => $this->row($s, $user), [
            'status_counts' => $counts,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $sensitive = $user->can('students.view_sensitive');
        $query = $this->filtered($request)->orderBy('full_name');
        Audit::log('student.exported', 'öğrenci listesini Excel olarak dışa aktardı.');

        return response()->streamDownload(function () use ($query, $sensitive, $user) {
            $writer = new Writer();
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(array_filter([
                'Öğrenci No', 'Ad Soyad', $sensitive ? 'TC Kimlik' : null, 'Durum', 'Sınıf', 'Okul', 'Sınıf Seviyesi', 'Alan',
                'Telefon', 'Veli', 'Veli Telefon', $user->can('finance.view') ? 'Açık Bakiye' : null, $user->can('finance.view') ? 'Gecikmiş' : null, 'Kayıt Tarihi',
            ], fn ($v) => $v !== null)));

            $query->chunk(500, function ($chunk) use ($writer, $sensitive, $user) {
                foreach ($chunk as $s) {
                    $g = $s->guardians->first();
                    $writer->addRow(Row::fromValues(array_values(array_filter([
                        $s->student_no, $s->full_name, $sensitive ? (Sensitive::decrypt($s->national_id_encrypted) ?? '') : null,
                        Student::STATUSES[$s->status] ?? $s->status, $s->currentClassGroups->pluck('name')->join(', '), $s->school_name ?? '',
                        $s->school_grade ?? '', $s->field ?? '', $sensitive ? ($s->phone ?? '') : (Sensitive::maskPhone($s->phone) ?? ''),
                        $g?->full_name ?? '', $sensitive ? ($g?->phone ?? '') : (Sensitive::maskPhone($g?->phone) ?? ''),
                        $user->can('finance.view') ? (float) $s->open_balance : null, $user->can('finance.view') ? (float) $s->overdue_balance : null,
                        $s->registered_on?->format('d.m.Y') ?? '',
                    ], fn ($v) => $v !== null))));
                }
            });
            $writer->close();
        }, 'ogrenciler-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Öğrenci 360° — Student Command Center tek istekte ana veri. Sekmeler ayrı uçlardan tembel yüklenir. */
    public function show(Request $request, Student $student, StudentInsights $insights): JsonResponse
    {
        $user = $request->user();
        $canFinance = $user->can('finance.view');
        $student->load([
            'guardians', 'guidanceTeacher:id,first_name,last_name', 'currentClassGroups.program:id,name,color', 'currentClassGroups.advisor:id,first_name,last_name',
            'tags:id,name,color', 'goals' => fn ($q) => $q->where('is_active', true),
        ]);
        $today = CarbonImmutable::today();

        $enrollments = $student->enrollments()->with(['program:id,name', 'term:id,name', 'classGroup:id,name'])->latest('enrolled_on')->get();

        $presence = DB::table('daily_presences')->where('student_id', $student->id)->where('date', $today->toDateString())
            ->first(['first_entry_at', 'last_exit_at', 'is_inside', 'minutes_inside']);

        $attendance30 = DB::table('attendances')->where('student_id', $student->id)->where('date', '>=', $today->subDays(30)->toDateString())
            ->selectRaw("COUNT(*) AS total, SUM(status='present') AS present, SUM(status='late') AS late, SUM(status='absent') AS absent, SUM(status IN ('excused','medical')) AS excused")->first();

        $exams = DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->where('r.student_id', $student->id)->where('e.status', 'results_published')->whereNull('e.deleted_at')
            ->orderByDesc('e.exam_date')->limit(15)
            ->get(['r.id', 'e.id as exam_id', 'e.name', 'e.exam_date', 't.code as type', 'r.net', 'r.score', 'r.correct', 'r.wrong', 'r.blank', 'r.institution_rank', 'r.class_rank', 'r.national_rank', 'e.participant_count']);
        $sectionNets = DB::table('exam_result_sections as s')->join('exam_sections as es', 'es.id', '=', 's.exam_section_id')
            ->whereIn('s.exam_result_id', $exams->pluck('id'))->orderBy('es.sort')
            ->get(['s.exam_result_id', 'es.code', 'es.name', 's.net', 's.correct', 's.wrong', 's.blank'])->groupBy('exam_result_id');
        $exams = $exams->map(function ($e) use ($sectionNets) {
            $e->sections = $sectionNets[$e->id] ?? [];

            return $e;
        });

        $topics = DB::table('student_topic_stats as st')->join('topics as tp', 'tp.id', '=', 'st.topic_id')->join('subjects as sb', 'sb.id', '=', 'tp.subject_id')
            ->where('st.student_id', $student->id)->where('st.asked', '>=', 3)
            ->get(['tp.id', 'tp.name as topic', 'sb.name as subject', 'st.asked', 'st.correct', DB::raw('ROUND(st.correct / st.asked * 100) AS rate')])
            ->sortByDesc('rate')->values();

        $groupIds = $student->currentClassGroups->pluck('id');
        $upcoming = DB::table('lesson_sessions as ls')->join('subjects as s', 's.id', '=', 'ls.subject_id')->join('classrooms as c', 'c.id', '=', 'ls.classroom_id')
            ->join('teachers as t', 't.id', '=', 'ls.teacher_id')
            ->whereIn('ls.class_group_id', $groupIds)->where('ls.status', '!=', 'cancelled')->where('ls.ends_at', '>', now())
            ->orderBy('ls.starts_at')->limit(6)
            ->get(['ls.id', 'ls.starts_at', 'ls.ends_at', 's.name as subject', 'c.name as classroom', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")]);

        $homework = DB::table('homework_submissions as hs')->join('homework as h', 'h.id', '=', 'hs.homework_id')->whereNull('h.deleted_at')
            ->where('hs.student_id', $student->id)
            ->selectRaw("COUNT(*) AS total, SUM(hs.status IN ('submitted','late')) AS done, SUM(hs.status IN ('assigned','seen') AND h.due_at > NOW()) AS open, SUM(hs.status='missed') AS missed")->first();

        $risk = $insights->riskFor($student);
        $riskFactors = collect($risk->factors)->reject(fn ($f) => ! $canFinance && ! empty($f['finance']))->values();

        $finance = null;
        if ($canFinance) {
            $inst = Installment::query()->where('student_id', $student->id)->where('status', '!=', 'cancelled')
                ->selectRaw("SUM(amount) AS total, SUM(paid_amount) AS paid, SUM(amount - paid_amount) AS remaining,
                    SUM(CASE WHEN status='overdue' THEN amount - paid_amount ELSE 0 END) AS overdue, SUM(status='overdue') AS overdue_count")->first();
            $next = Installment::query()->where('student_id', $student->id)->whereIn('status', ['pending', 'partial', 'overdue'])->orderBy('due_date')->first(['id', 'due_date', 'amount', 'paid_amount', 'status', 'sequence']);
            $finance = [
                'list_price' => (string) $enrollments->where('status', '!=', 'withdrawn')->sum('list_price'),
                'discount' => (string) $enrollments->where('status', '!=', 'withdrawn')->sum(fn ($e) => $e->discount_amount + $e->scholarship_amount),
                'total' => (string) ($inst->total ?? 0), 'paid' => (string) ($inst->paid ?? 0), 'remaining' => (string) ($inst->remaining ?? 0),
                'overdue' => (string) ($inst->overdue ?? 0), 'overdue_count' => (int) $inst->overdue_count,
                'next_installment' => $next,
            ];
        }

        $sensitive = $user->can('students.view_sensitive');
        // Ticari ileti onayları (formda gösterilir): öğrenci + velileri
        $consentService = app(\App\Services\Campaigns\ConsentService::class);
        $studentConsents = $consentService->marketingState('student', [$student->id])[$student->id];
        $guardianConsents = $consentService->marketingState('guardian', $student->guardians->pluck('id')->all());

        return response()->json([
            'student' => [
                ...$this->row($student, $user),
                'marketing_consents' => $studentConsents,
                'first_name' => $student->first_name, 'last_name' => $student->last_name,
                'national_id_masked' => Sensitive::maskNationalId($student->national_id_last4),
                'birth_date' => $student->birth_date?->toDateString(), 'gender' => $student->gender,
                'school_name' => $student->school_name, 'target_university' => $student->target_university, 'target_department' => $student->target_department,
                'whatsapp_phone' => $sensitive ? $student->whatsapp_phone : Sensitive::maskPhone($student->whatsapp_phone),
                'email' => $student->email, 'address' => $student->address, 'medical_notes' => $student->medical_notes, 'notes' => $student->notes,
                'registered_on' => $student->registered_on?->toDateString(),
                'guidance_teacher' => $student->guidanceTeacher ? ['id' => $student->guidanceTeacher->id, 'name' => $student->guidanceTeacher->full_name] : null,
                'guardians' => $student->guardians->map(fn ($g) => [
                    'id' => $g->id, 'name' => $g->full_name, 'first_name' => $g->first_name, 'last_name' => $g->last_name,
                    'phone' => $sensitive ? $g->phone : Sensitive::maskPhone($g->phone), 'whatsapp_phone' => $sensitive ? $g->whatsapp_phone : Sensitive::maskPhone($g->whatsapp_phone),
                    'email' => $g->email, 'occupation' => $g->occupation, 'relationship' => $g->pivot->relationship,
                    'is_primary' => (bool) $g->pivot->is_primary, 'is_financially_responsible' => (bool) $g->pivot->is_financially_responsible,
                    'receives_notifications' => (bool) $g->pivot->receives_notifications,
                    'marketing_consents' => $guardianConsents[$g->id] ?? null,
                ]),
                'class_groups' => $student->currentClassGroups->map(fn ($g) => [
                    'id' => $g->id, 'name' => $g->name, 'program' => $g->program?->name, 'program_color' => $g->program?->color,
                    'advisor' => $g->advisor?->full_name, 'joined_on' => $g->pivot->joined_on,
                ]),
                'goals' => $student->goals,
                'tag_ids' => $student->tags->pluck('id'),
            ],
            'enrollments' => $enrollments->map(fn ($e) => [
                'id' => $e->id, 'enrollment_no' => $e->enrollment_no, 'program' => $e->program?->name, 'term' => $e->term?->name,
                'class_group' => $e->classGroup?->name, 'status' => $e->status, 'enrolled_on' => $e->enrolled_on->toDateString(),
                'net_price' => $canFinance ? (string) $e->net_price : null,
            ]),
            'today' => $presence,
            'attendance_30' => $attendance30,
            'exams' => $exams,
            'topics' => ['strong' => $topics->take(5)->values(), 'weak' => $topics->sortBy('rate')->take(5)->values()],
            'upcoming_lessons' => $upcoming,
            'homework' => $homework,
            'risk' => ['score' => $risk->score, 'level' => $risk->level, 'factors' => $riskFactors, 'insights' => $risk->insights, 'calculated_at' => $risk->calculated_at],
            'finance' => $finance,
            'counts' => [
                'guidance' => DB::table('guidance_meetings')->where('student_id', $student->id)->whereNull('deleted_at')->count(),
                'documents' => DB::table('documents')->where('documentable_type', 'student')->where('documentable_id', $student->id)->whereNull('deleted_at')->count(),
                'notes' => DB::table('student_notes')->where('student_id', $student->id)->whereNull('deleted_at')->count(),
                'messages' => DB::table('outbound_messages')->where('student_id', $student->id)->count(),
                'observations' => DB::table('student_observations')->where('student_id', $student->id)->whereNull('deleted_at')->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $student = $this->students->create($this->validated($request));

        if ($request->filled('enrollment')) {
            abort_unless($request->user()->can('enrollments.create'), 403);
            app(EnrollmentService::class)->enroll($student, $this->validatedEnrollment($request));
        }

        return response()->json(['message' => 'Öğrenci kaydedildi.', 'id' => $student->id], 201);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        $this->students->update($student, $this->validated($request, $student));

        return $this->ok('Öğrenci bilgileri güncellendi.');
    }

    public function destroy(Student $student): JsonResponse
    {
        $this->students->delete($student);

        return $this->ok('Öğrenci kaydı silindi.');
    }

    public function changeStatus(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Student::STATUSES))], 'note' => ['nullable', 'string', 'max:300']]);
        $this->students->changeStatus($student, $data['status'], $data['note'] ?? null);

        return $this->ok('Öğrenci durumu güncellendi.');
    }

    /** KVKK: TC kimlik numarasını açık görüntüleme her seferinde denetim kaydına yazılır. */
    public function revealNationalId(Student $student): JsonResponse
    {
        Audit::log('student.national_id_viewed', "{$student->full_name} öğrencisinin TC kimlik numarasını görüntüledi.", $student);

        return response()->json(['national_id' => Sensitive::decrypt($student->national_id_encrypted)]);
    }

    public function uploadPhoto(Request $request, Student $student): JsonResponse
    {
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144']]);

        $manager = new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Gd\Driver());
        $image = $manager->decodePath($request->file('photo')->getRealPath())->cover(480, 480)->encodeUsingFileExtension('webp', quality: 82);
        $path = 'students/'.$student->id.'-'.bin2hex(random_bytes(6)).'.webp';
        Storage::disk('public')->put($path, (string) $image);

        if ($student->photo_path) {
            Storage::disk('public')->delete($student->photo_path);
        }
        $student->forceFill(['photo_path' => $path])->save();

        return response()->json(['message' => 'Fotoğraf güncellendi.', 'photo_url' => Storage::disk('public')->url($path)]);
    }

    /** Toplu işlemler: etiket, sınıf değişikliği, durum. */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:1000'], 'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['tag_add', 'tag_remove', 'class_change', 'status'])],
            'tag_id' => ['required_if:action,tag_add,tag_remove', 'integer'],
            'class_group_id' => ['required_if:action,class_change', 'integer'],
            'status' => ['required_if:action,status', Rule::in(array_keys(Student::STATUSES))],
        ]);

        $students = Student::query()->whereIn('id', $data['ids'])->get();
        $done = 0;
        $errors = [];

        DB::transaction(function () use ($data, $students, &$done, &$errors) {
            foreach ($students as $student) {
                try {
                    match ($data['action']) {
                        'tag_add' => $student->tags()->syncWithoutDetaching([Tag::query()->findOrFail($data['tag_id'])->id]),
                        'tag_remove' => $student->tags()->detach($data['tag_id']),
                        'class_change' => app(EnrollmentService::class)->assignClassGroup($student, \App\Models\ClassGroup::query()->findOrFail($data['class_group_id']), now()->toDateString()),
                        'status' => $this->students->changeStatus($student, $data['status']),
                    };
                    $done++;
                } catch (BusinessRuleException $e) {
                    $errors[] = "{$student->full_name}: {$e->getMessage()}";
                }
            }
        });

        Audit::log('student.bulk_'.$data['action'], "{$done} öğrenci üzerinde toplu işlem yaptı ({$data['action']}).");

        return response()->json(['message' => "{$done} öğrenci güncellendi.", 'done' => $done, 'errors' => $errors]);
    }

    /** Filtre seçenekleri (sınıflar, programlar, etiketler, rehber öğretmenler) tek istekte. */
    public function options(): JsonResponse
    {
        return response()->json([
            'class_groups' => DB::table('class_groups')->where('branch_id', app(\App\Support\BranchContext::class)->id())->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'program_id', 'capacity']),
            'programs' => \App\Models\Program::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'kind']),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name', 'color']),
            'teachers' => \App\Models\Teacher::query()->where('is_active', true)->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'terms' => \App\Models\AcademicTerm::query()->orderByDesc('starts_on')->get(['id', 'name', 'is_current']),
            'packages' => \App\Models\EducationPackage::query()->where('is_active', true)->get(['id', 'name', 'program_id', 'list_price', 'default_installments']),
            'statuses' => Student::STATUSES,
        ]);
    }

    // ------------------------------------------------------------------ sekmeler

    public function attendance(Request $request, Student $student): JsonResponse
    {
        $rows = DB::table('attendances as a')->join('lesson_sessions as ls', 'ls.id', '=', 'a.lesson_session_id')
            ->join('subjects as s', 's.id', '=', 'ls.subject_id')->join('teachers as t', 't.id', '=', 'ls.teacher_id')
            ->where('a.student_id', $student->id)
            ->when($request->query('status'), fn ($q, $st) => $q->where('a.status', $st))
            ->orderByDesc('ls.starts_at')
            ->paginate($this->perPage($request, 30), ['a.id', 'a.date', 'a.status', 'a.late_minutes', 'a.method', 'a.note', 'a.lesson_session_id', 'ls.starts_at', 'ls.ends_at', 'ls.topic_note', 's.name as subject', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")]);

        // İşlenen konular (gelmediyse kaçırdığı konular) — toplu getir
        $sessionIds = $rows->getCollection()->pluck('lesson_session_id')->filter()->unique()->values();
        $topicMap = $sessionIds->isEmpty() ? [] : DB::table('lesson_session_topic as lst')
            ->join('topics as tp', 'tp.id', '=', 'lst.topic_id')
            ->whereIn('lst.lesson_session_id', $sessionIds)
            ->orderBy('lst.sort')->orderBy('tp.name')
            ->get(['lst.lesson_session_id as sid', 'tp.id', 'tp.name', 'tp.outcome_code'])
            ->groupBy('sid')
            ->map(fn ($g) => $g->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'outcome_code' => $r->outcome_code])->values()->all())
            ->all();

        $presences = DB::table('daily_presences')->where('student_id', $student->id)->orderByDesc('date')->limit(31)
            ->get(['date', 'first_entry_at', 'last_exit_at', 'minutes_inside', 'is_inside']);

        $summary = DB::table('attendances')->where('student_id', $student->id)
            ->selectRaw("SUM(status='present') AS present, SUM(status='late') AS late, SUM(status='absent') AS absent, SUM(status='excused') AS excused, SUM(status='medical') AS medical, COUNT(*) AS total")->first();

        return $this->paginated($rows, fn ($r) => [...(array) $r, 'topics' => $topicMap[$r->lesson_session_id] ?? []], ['presences' => $presences, 'summary' => $summary]);
    }

    public function payments(Request $request, Student $student): JsonResponse
    {
        abort_unless($request->user()->can('finance.view'), 403);

        return response()->json([
            'installments' => Installment::query()->where('student_id', $student->id)->with('enrollment:id,enrollment_no')->orderBy('due_date')->get()
                ->map(fn ($i) => [...$i->only(['id', 'enrollment_id', 'sequence', 'amount', 'paid_amount', 'status', 'paid_at']), 'due_date' => $i->due_date->toDateString(), 'enrollment_no' => $i->enrollment?->enrollment_no, 'remaining' => $i->remaining()]),
            'payments' => $student->payments()->with(['account:id,name', 'receiver:id,name'])->orderByDesc('paid_at')->get()
                ->map(fn ($p) => [...$p->only(['id', 'receipt_no', 'amount', 'method', 'paid_at', 'reference', 'note', 'voided_at', 'void_reason']), 'account' => $p->account?->name, 'received_by' => $p->receiver?->name]),
        ]);
    }

    public function timeline(Request $request, Student $student): JsonResponse
    {
        $before = $request->date('before') ?? now()->addDay();
        $limit = 60;
        $canFinance = $request->user()->can('finance.view');

        $items = collect();
        $items = $items->concat(DB::table('activity_feed')->where('student_id', $student->id)->where('occurred_at', '<', $before)
            ->when(! $canFinance, fn ($q) => $q->where('kind', '!=', 'payment'))
            ->orderByDesc('occurred_at')->limit($limit)->get(['kind', 'message', 'occurred_at as at'])
            ->map(fn ($r) => ['kind' => $r->kind, 'title' => $r->message, 'at' => $r->at]));

        $items = $items->concat(DB::table('guidance_meetings')->where('student_id', $student->id)->whereNull('deleted_at')->where('met_at', '<', $before)
            ->orderByDesc('met_at')->limit(20)->get(['kind', 'summary', 'met_at'])
            ->map(fn ($r) => ['kind' => 'guidance', 'title' => 'Rehberlik görüşmesi', 'detail' => mb_strimwidth($r->summary, 0, 140, '…'), 'at' => $r->met_at]));

        $items = $items->concat(DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->where('r.student_id', $student->id)
            ->where('e.status', 'results_published')->where('e.exam_date', '<', $before)->orderByDesc('e.exam_date')->limit(20)
            ->get(['e.name', 'r.net', 'r.institution_rank', 'e.exam_date'])
            ->map(fn ($r) => ['kind' => 'exam', 'title' => "{$r->name}: ".number_format($r->net, 2, ',', '').' net', 'detail' => $r->institution_rank ? "Kurum sırası {$r->institution_rank}" : null, 'at' => $r->exam_date.' 12:00:00']));

        $items = $items->concat(DB::table('student_notes as n')->leftJoin('users as u', 'u.id', '=', 'n.user_id')->where('n.student_id', $student->id)->whereNull('n.deleted_at')
            ->where('n.created_at', '<', $before)->orderByDesc('n.created_at')->limit(20)->get(['n.body', 'n.created_at', 'u.name'])
            ->map(fn ($r) => ['kind' => 'note', 'title' => 'Not eklendi'.($r->name ? " · {$r->name}" : ''), 'detail' => mb_strimwidth($r->body, 0, 140, '…'), 'at' => $r->created_at]));

        $sorted = $items->sortByDesc('at')->take($limit)->values();

        return response()->json(['data' => $sorted, 'next_before' => $sorted->count() >= $limit ? $sorted->last()['at'] : null]);
    }

    public function notes(Student $student): JsonResponse
    {
        return response()->json(['data' => $student->notesList()->with('user:id,name')->orderByDesc('is_pinned')->latest()->get()]);
    }

    public function storeNote(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:3000'], 'is_pinned' => ['boolean']]);
        $note = $student->notesList()->create($data + ['user_id' => $request->user()->id]);

        return response()->json(['message' => 'Not eklendi.', 'data' => $note->load('user:id,name')], 201);
    }

    public function destroyNote(Request $request, Student $student, int $note): JsonResponse
    {
        $model = $student->notesList()->findOrFail($note);
        abort_unless($model->user_id === $request->user()->id || $request->user()->can('students.update'), 403);
        $model->delete();

        return $this->ok('Not silindi.');
    }

    public function homework(Student $student): JsonResponse
    {
        return response()->json(['data' => DB::table('homework_submissions as hs')->join('homework as h', 'h.id', '=', 'hs.homework_id')
            ->join('subjects as s', 's.id', '=', 'h.subject_id')->join('teachers as t', 't.id', '=', 'h.teacher_id')
            ->whereNull('h.deleted_at')->where('hs.student_id', $student->id)->orderByDesc('h.due_at')->limit(100)
            ->get(['hs.id', 'h.title', 'h.due_at', 'hs.status', 'hs.submitted_at', 'hs.score', 'hs.teacher_note', 's.name as subject', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")])]);
    }

    public function messages(Request $request, Student $student): JsonResponse
    {
        abort_unless($request->user()->can('messages.view'), 403);

        return $this->paginated(
            DB::table('outbound_messages')->where('student_id', $student->id)->orderByDesc('id')->paginate($this->perPage($request, 30),
                ['id', 'channel', 'to', 'template_key', 'body', 'status', 'error', 'sent_at', 'delivered_at', 'read_at', 'created_at']),
            fn ($m) => [...(array) $m, 'to' => $request->user()->can('students.view_sensitive') ? $m->to : Sensitive::maskPhone($m->to)],
        );
    }

    // ------------------------------------------------------------------ yardımcılar

    private function filtered(Request $request): Builder
    {
        $user = $request->user();
        $query = Student::query()->with(['currentClassGroups:id,name', 'guardians' => fn ($q) => $q->wherePivot('is_primary', true), 'tags:id,name,color']);
        $query->select('students.*');
        $query->addSelect([
            'risk_level' => DB::table('student_risk_scores')->select('level')->whereColumn('student_id', 'students.id')->limit(1),
            'risk_score' => DB::table('student_risk_scores')->select('score')->whereColumn('student_id', 'students.id')->limit(1),
            'is_inside' => DB::table('daily_presences')->select('is_inside')->whereColumn('student_id', 'students.id')->where('date', now()->toDateString())->limit(1),
            // Liste zenginleştirme: son 30 gün devam yüzdesi, son yayınlanan deneme neti/tarihi, güncel kayıt programı (alt sorgu; N+1 yok)
            'attendance_30' => DB::table('attendances')->selectRaw("ROUND(SUM(status IN ('present','late')) / COUNT(*) * 100)")
                ->whereColumn('student_id', 'students.id')->where('date', '>=', now()->subDays(30)->toDateString()),
            'last_exam_net' => DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->select('r.net')
                ->whereColumn('r.student_id', 'students.id')->where('e.status', 'results_published')->whereNull('e.deleted_at')->orderByDesc('e.exam_date')->orderByDesc('e.id')->limit(1),
            'last_exam_date' => DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->select('e.exam_date')
                ->whereColumn('r.student_id', 'students.id')->where('e.status', 'results_published')->whereNull('e.deleted_at')->orderByDesc('e.exam_date')->orderByDesc('e.id')->limit(1),
            'program_name' => DB::table('enrollments as en')->join('programs as p', 'p.id', '=', 'en.program_id')->select('p.name')
                ->whereColumn('en.student_id', 'students.id')->whereIn('en.status', ['active', 'frozen', 'pending'])->whereNull('en.deleted_at')->orderByDesc('en.enrolled_on')->orderByDesc('en.id')->limit(1),
        ]);
        if ($user->can('finance.view')) {
            $query->addSelect([
                'open_balance' => DB::table('installments')->selectRaw('COALESCE(SUM(amount - paid_amount), 0)')->whereColumn('student_id', 'students.id')->whereIn('status', ['pending', 'partial', 'overdue']),
                'overdue_balance' => DB::table('installments')->selectRaw('COALESCE(SUM(amount - paid_amount), 0)')->whereColumn('student_id', 'students.id')->where('status', 'overdue'),
            ]);
        }

        return $this->baseFilters($query, $request);
    }

    private function baseFilters(Builder $query, Request $request, bool $ignoreStatus = false): Builder
    {
        $q = trim((string) $request->query('q'));
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q);
            $query->where(function (Builder $w) use ($q, $digits) {
                $words = array_filter(preg_split('/\s+/u', $q));
                if ($words && min(array_map('mb_strlen', $words)) >= 3) {
                    $w->whereRaw('MATCH(full_name, school_name) AGAINST (? IN BOOLEAN MODE)', [implode(' ', array_map(fn ($x) => '+'.preg_replace('/[+\-><()~*"@]/u', '', $x).'*', $words))]);
                } else {
                    $w->where('full_name', 'like', $q.'%')->orWhere('full_name', 'like', '% '.$q.'%');
                }
                if (strlen($digits) >= 3) {
                    $w->orWhere('student_no', $digits)->orWhere('phone', 'like', '%'.$digits);
                    if (strlen($digits) === 11) {
                        $w->orWhereIn('national_id_hash', Sensitive::hashes($digits));
                    }
                }
            });
        }

        if (! $ignoreStatus) {
            $status = $request->query('status', 'current');
            if ($status === 'current') {
                $query->whereIn('status', ['active', 'enrolled', 'frozen', 'pending']);
            } elseif ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        if ($groupId = $request->integer('class_group_id')) {
            $query->whereExists(fn ($s) => $s->from('class_group_student')->whereColumn('student_id', 'students.id')->where('class_group_id', $groupId)->whereNull('left_on'));
        }
        if ($programId = $request->integer('program_id')) {
            $query->whereExists(fn ($s) => $s->from('enrollments')->whereColumn('student_id', 'students.id')->where('program_id', $programId)->whereIn('status', ['active', 'frozen']));
        }
        if ($tagId = $request->integer('tag_id')) {
            $query->whereExists(fn ($s) => $s->from('taggables')->whereColumn('taggable_id', 'students.id')->where('taggable_type', 'student')->where('tag_id', $tagId));
        }
        if ($grade = $request->query('school_grade')) {
            $query->where('school_grade', $grade);
        }
        if ($field = $request->query('field')) {
            $query->where('field', $field);
        }
        if ($request->boolean('overdue') && $request->user()->can('finance.view')) {
            $query->whereExists(fn ($s) => $s->from('installments')->whereColumn('student_id', 'students.id')->where('status', 'overdue'));
        }
        if ($risk = $request->query('risk')) {
            $query->whereExists(fn ($s) => $s->from('student_risk_scores')->whereColumn('student_id', 'students.id')->where('level', $risk));
        }
        if ($request->boolean('inside')) {
            $query->whereExists(fn ($s) => $s->from('daily_presences')->whereColumn('student_id', 'students.id')->where('date', now()->toDateString())->where('is_inside', true));
        }
        $this->applyDateRange($query, $request, 'registered_on');

        return $query;
    }

    private function row(Student $s, $user): array
    {
        $sensitive = $user->can('students.view_sensitive');
        $guardian = $s->relationLoaded('guardians') ? $s->guardians->first() : null;

        return [
            'id' => $s->id,
            'student_no' => $s->student_no,
            'full_name' => $s->full_name,
            'photo_url' => $s->photo_path ? Storage::disk('public')->url($s->photo_path) : null,
            'status' => $s->status,
            'status_label' => Student::STATUSES[$s->status] ?? $s->status,
            'school_grade' => $s->school_grade,
            'field' => $s->field,
            'phone' => $sensitive ? $s->phone : Sensitive::maskPhone($s->phone),
            'class_groups' => $s->relationLoaded('currentClassGroups') ? $s->currentClassGroups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name]) : [],
            'guardian' => $guardian ? ['id' => $guardian->id, 'name' => $guardian->full_name, 'phone' => $sensitive ? $guardian->phone : Sensitive::maskPhone($guardian->phone)] : null,
            'tags' => $s->relationLoaded('tags') ? $s->tags->map->only(['id', 'name', 'color']) : [],
            'open_balance' => $user->can('finance.view') ? (string) ($s->open_balance ?? '0') : null,
            'overdue_balance' => $user->can('finance.view') ? (string) ($s->overdue_balance ?? '0') : null,
            'risk_level' => $s->risk_level ?? null,
            'is_inside' => (bool) ($s->is_inside ?? false),
            'registered_on' => $s->registered_on?->toDateString(),
            // Yalnız liste sorgusunda hesaplanır (alt sorgu); tekil kayıtta null döner
            'attendance_30' => isset($s->attendance_30) ? (int) $s->attendance_30 : null,
            'last_exam' => isset($s->last_exam_net) ? ['net' => (string) $s->last_exam_net, 'date' => $s->last_exam_date] : null,
            'program' => $s->program_name ?? null,
        ];
    }

    private function validated(Request $request, ?Student $student = null): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'national_id' => ['nullable', 'digits:11'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['female', 'male', 'other'])],
            'school_name' => ['nullable', 'string', 'max:160'],
            'school_grade' => ['nullable', 'string', 'max:20'],
            'field' => ['nullable', Rule::in(['SAY', 'EA', 'SOZ', 'DIL', 'TYT', 'LGS'])],
            'target_university' => ['nullable', 'string', 'max:160'],
            'target_department' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:20'],
            'whatsapp_phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(array_keys(Student::STATUSES))],
            'guidance_teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')],
            'registered_on' => ['nullable', 'date'],
            'medical_notes' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tag_ids' => ['sometimes', 'array'], 'tag_ids.*' => ['integer', Rule::exists('tags', 'id')],
            'guardians' => [$student ? 'sometimes' : 'required', 'array', 'min:1', 'max:4'],
            'guardians.*.id' => ['nullable', 'integer', Rule::exists('guardians', 'id')],
            'guardians.*.first_name' => ['required_without:guardians.*.id', 'nullable', 'string', 'max:80'],
            'guardians.*.last_name' => ['required_without:guardians.*.id', 'nullable', 'string', 'max:80'],
            'guardians.*.phone' => ['required_without:guardians.*.id', 'nullable', 'string', 'max:20'],
            'guardians.*.whatsapp_phone' => ['nullable', 'string', 'max:20'],
            'guardians.*.email' => ['nullable', 'email'],
            'guardians.*.occupation' => ['nullable', 'string', 'max:120'],
            'guardians.*.relationship' => ['nullable', Rule::in(['mother', 'father', 'guardian', 'other', 'parent'])],
            'guardians.*.is_primary' => ['boolean'],
            'guardians.*.is_financially_responsible' => ['boolean'],
            'guardians.*.receives_notifications' => ['boolean'],
            'guardians.*.whatsapp_consent' => ['boolean'],
            // Ticari ileti onayı (SMS / e-posta / WhatsApp) — kaynak "Kayıt formu", varsayılan kapalı
            'marketing_consents' => ['sometimes', 'array'],
            'marketing_consents.sms' => ['nullable', 'boolean'],
            'marketing_consents.email' => ['nullable', 'boolean'],
            'marketing_consents.whatsapp' => ['nullable', 'boolean'],
            'guardians.*.marketing_consents' => ['sometimes', 'array'],
            'guardians.*.marketing_consents.sms' => ['nullable', 'boolean'],
            'guardians.*.marketing_consents.email' => ['nullable', 'boolean'],
            'guardians.*.marketing_consents.whatsapp' => ['nullable', 'boolean'],
        ], [
            'guardians.required' => 'En az bir veli bilgisi girilmelidir.',
            'national_id.digits' => 'TC kimlik numarası 11 haneli olmalıdır.',
        ]);
    }

    private function validatedEnrollment(Request $request): array
    {
        return $request->validate([
            'enrollment.academic_term_id' => ['required', 'integer'],
            'enrollment.program_id' => ['required', 'integer'],
            'enrollment.education_package_id' => ['nullable', 'integer'],
            'enrollment.class_group_id' => ['nullable', 'integer'],
            'enrollment.list_price' => ['required', 'numeric', 'min:0'],
            'enrollment.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'enrollment.discount_reason' => ['nullable', 'string', 'max:200'],
            'enrollment.scholarship_amount' => ['nullable', 'numeric', 'min:0'],
            'enrollment.scholarship_reason' => ['nullable', 'string', 'max:200'],
            'enrollment.enrolled_on' => ['required', 'date'],
            'enrollment.down_payment' => ['nullable', 'numeric', 'min:0'],
            'enrollment.installment_count' => ['required', 'integer', 'min:0', 'max:36'],
            'enrollment.first_due_date' => ['required', 'date'],
        ])['enrollment'];
    }
}
