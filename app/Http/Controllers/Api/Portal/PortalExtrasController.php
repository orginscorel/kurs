<?php

namespace App\Http\Controllers\Api\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Concerns\IsoJson;
use App\Http\Middleware\EnsurePortalStudent;
use App\Models\ContactRequest;
use App\Models\Document;
use App\Models\Guardian;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Student;
use App\Models\StudentObservation;
use App\Services\Academic\HomeworkService;
use App\Services\Auth\Impersonation;
use App\Services\Portal\AnnouncementAudience;
use App\Support\Audit;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Öğrenci/veli portalı ek uçları: ödev ayrıntısı + teslim (metin/dosya), gelişim (net trendi, konu eksikleri,
 * yaklaşan sınavlar), etütler, öğretmen geri bildirimleri, öğretmenler + veli talepleri, duyuru okundu.
 * Öğrenci EnsurePortalStudent ile çözülür. Teslim/okundu gibi yazma işlemleri YALNIZ öğrencinin kendisine
 * (veli hesabı teslim yapamaz), talep oluşturma YALNIZ veliye açıktır; önizlemede yazma zaten kapalıdır.
 */
class PortalExtrasController extends ApiController
{
    use IsoJson;

    public const MAX_SUBMISSION_FILES = 5;

    public function __construct(private readonly HomeworkService $homework) {}

    private function student(Request $request): Student
    {
        return $request->attributes->get(EnsurePortalStudent::ATTRIBUTE);
    }

    private function guardian(Request $request): ?Guardian
    {
        return EnsurePortalStudent::isGuardianRequest($request) ? $request->attributes->get(EnsurePortalStudent::GUARDIAN_ATTRIBUTE) : null;
    }

    private function assertStudentAccount(Request $request): void
    {
        if ($this->guardian($request) || ! $request->user()->isStudent()) {
            throw new BusinessRuleException('Bu işlemi yalnız öğrenci kendi hesabından yapabilir.', 'student_only', [], 403);
        }
    }

    // ------------------------------------------------------------------ ödev ayrıntısı + teslim

    private function submission(Request $request, int $id): HomeworkSubmission
    {
        $sub = HomeworkSubmission::query()->whereKey($id)->where('student_id', $this->student($request)->id)->firstOrFail();
        // Silinmiş ödevin teslimi görünmez
        Homework::query()->whereKey($sub->homework_id)->firstOrFail();

        return $sub;
    }

    public function homeworkShow(Request $request, int $submission): JsonResponse
    {
        $sub = $this->submission($request, $submission);
        $hw = Homework::query()->with(['subject:id,name,color', 'teacher:id,first_name,last_name,title', 'topic:id,name', 'classGroup:id,name'])->findOrFail($sub->homework_id);

        $isStudent = ! $this->guardian($request) && $request->user()->isStudent();
        $graded = HomeworkService::isGraded($sub);
        $files = $this->homework->submissionFiles($sub->id)->get();

        return $this->isoJson([
            'id' => $sub->id,
            'homework' => [
                'title' => $hw->title,
                'description' => $hw->description,
                'subject' => $hw->subject?->name,
                'subject_color' => $hw->subject?->color,
                'teacher' => $hw->teacher ? trim(($hw->teacher->title ? $hw->teacher->title.' ' : '').$hw->teacher->full_name) : null,
                'topic' => $hw->topic?->name,
                'class_group' => $hw->classGroup?->name,
                'assigned_at' => $hw->assigned_at?->toAtomString(),
                'due_at' => $hw->due_at->toAtomString(),
                'is_past_due' => $hw->due_at->isPast(),
            ],
            'status' => $sub->status,
            'status_label' => \App\Http\Controllers\Api\Portal\PortalController::HOMEWORK_LABELS[$sub->status] ?? $sub->status,
            'seen_at' => $sub->seen_at?->toAtomString(),
            'submitted_at' => $sub->submitted_at?->toAtomString(),
            'answer_text' => $sub->answer_text,
            'score' => $sub->score,
            'teacher_note' => $sub->teacher_note,
            'graded_at' => $sub->graded_at?->toAtomString(),
            'teacher_files' => $this->homework->documents($hw)->get()->map(fn (Document $d) => $this->docRow($d)),
            'my_files' => $files->map(fn (Document $d) => $this->docRow($d)),
            'can_submit' => $isStudent && ! $graded && ! Impersonation::activeFor($request),
            'locked_reason' => $graded ? 'Öğretmen değerlendirdiği için teslim değiştirilemez.' : null,
            'max_files' => self::MAX_SUBMISSION_FILES,
            'max_file_mb' => (int) (HomeworkService::MAX_FILE_KB / 1024),
            'allowed_ext' => HomeworkService::ALLOWED_EXT,
        ]);
    }

    /** Öğrenci teslimi: metin cevap ve/veya dosya(lar). Değerlendirilene kadar güncellenebilir. */
    public function homeworkSubmit(Request $request, int $submission): JsonResponse
    {
        $this->assertStudentAccount($request);
        $sub = $this->submission($request, $submission);
        if (HomeworkService::isGraded($sub)) {
            throw new BusinessRuleException('Öğretmen bu ödevi değerlendirdi; teslim artık değiştirilemez.', 'homework_graded', [], 422);
        }

        $data = $request->validate([
            'answer_text' => ['nullable', 'string', 'max:5000'],
            'files' => ['nullable', 'array', 'max:'.self::MAX_SUBMISSION_FILES],
            'files.*' => ['file', 'max:'.HomeworkService::MAX_FILE_KB],
        ], ['files.*.max' => 'Dosya en fazla 15 MB olabilir.', 'files.max' => 'En fazla '.self::MAX_SUBMISSION_FILES.' dosya ekleyebilirsin.']);

        $newFiles = (array) $request->file('files', []);
        $existing = $this->homework->submissionFiles($sub->id)->count();
        $answer = trim((string) ($data['answer_text'] ?? ''));
        if ($answer === '' && $newFiles === [] && $existing === 0) {
            throw new BusinessRuleException('Teslim için bir açıklama yaz ya da dosya ekle.', 'submission_empty', [], 422);
        }
        if ($existing + count($newFiles) > self::MAX_SUBMISSION_FILES) {
            throw new BusinessRuleException('Bir teslime en fazla '.self::MAX_SUBMISSION_FILES.' dosya eklenebilir.', 'too_many_files', [], 422);
        }

        $hw = Homework::query()->findOrFail($sub->homework_id);
        DB::transaction(function () use ($hw, $sub, $answer, $newFiles, $request) {
            foreach ($newFiles as $file) {
                $this->homework->attachSubmissionFile($sub, $file, $request->user());
            }
            $this->homework->submit($hw, $sub->student_id, $answer);
        });
        $sub->refresh();

        Audit::log('homework.submitted', sprintf('"%s" ödevini teslim etti%s.', $hw->title, $newFiles ? ' ('.count($newFiles).' dosya)' : ''), $sub);

        return $this->isoJson(['message' => $sub->status === 'late' ? 'Ödevin geç teslim olarak kaydedildi.' : 'Ödevin teslim edildi.', 'status' => $sub->status]);
    }

    /** Öğrenci ödevi açınca "görüldü". */
    public function homeworkSeen(Request $request, int $submission): JsonResponse
    {
        $this->assertStudentAccount($request);
        $sub = $this->submission($request, $submission);
        if ($sub->status === 'assigned') {
            $sub->forceFill(['status' => 'seen', 'seen_at' => now()])->save();
        } elseif (! $sub->seen_at) {
            $sub->forceFill(['seen_at' => now()])->save();
        }

        return $this->ok('Görüldü.', ['status' => $sub->status]);
    }

    public function homeworkFile(Request $request, int $submission, int $document): StreamedResponse
    {
        $sub = $this->submission($request, $submission);
        $doc = Document::query()->whereKey($document)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('documentable_type', 'homework')->where('documentable_id', $sub->homework_id))
                ->orWhere(fn ($w) => $w->where('documentable_type', 'homework_submission')->where('documentable_id', $sub->id)))
            ->firstOrFail();
        if (! Storage::disk($doc->disk)->exists($doc->path)) {
            throw new BusinessRuleException('Dosya sunucuda bulunamadı.', 'file_missing', [], 404);
        }

        return Storage::disk($doc->disk)->download($doc->path, $doc->title);
    }

    public function homeworkFileDestroy(Request $request, int $submission, int $document): JsonResponse
    {
        $this->assertStudentAccount($request);
        $sub = $this->submission($request, $submission);
        if (HomeworkService::isGraded($sub)) {
            throw new BusinessRuleException('Öğretmen bu ödevi değerlendirdi; dosyalar değiştirilemez.', 'homework_graded', [], 422);
        }
        $doc = Document::query()->whereKey($document)->where('documentable_type', 'homework_submission')->where('documentable_id', $sub->id)->firstOrFail();
        Storage::disk($doc->disk)->delete($doc->path);
        $doc->forceDelete();

        return $this->ok('Dosya kaldırıldı.');
    }

    // ------------------------------------------------------------------ gelişim

    public function progress(Request $request): JsonResponse
    {
        $student = $this->student($request);

        $results = DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->where('r.student_id', $student->id)->where('e.status', 'results_published')->whereNull('e.deleted_at')
            ->orderBy('e.exam_date')->orderBy('e.id')->limit(60)
            ->get(['r.id', 'e.name', 'e.exam_date', 't.code as type', 't.name as type_name', 'r.net']);

        $sections = $results->isEmpty() ? collect() : DB::table('exam_result_sections as rs')->join('exam_sections as es', 'es.id', '=', 'rs.exam_section_id')
            ->whereIn('rs.exam_result_id', $results->pluck('id'))
            ->get(['rs.exam_result_id', 'es.code', 'es.name', 'es.sort', 'rs.net', 'rs.correct', 'rs.wrong', 'rs.blank'])->groupBy('exam_result_id');

        $byType = $results->groupBy('type')->map(function (Collection $rows, string $type) use ($sections) {
            $sectionNames = [];
            $points = $rows->map(function ($r) use ($sections, &$sectionNames) {
                $point = ['exam' => $r->name, 'date' => $r->exam_date, 'net' => (float) $r->net];
                foreach ($sections[$r->id] ?? [] as $s) {
                    $sectionNames[$s->code] = ['code' => $s->code, 'name' => $s->name, 'sort' => (int) $s->sort];
                    $point[$s->code] = (float) $s->net;
                }

                return $point;
            })->values();

            $subjects = collect($sectionNames)->sortBy('sort')->values()->map(function ($sec) use ($points) {
                $vals = $points->pluck($sec['code'])->filter(fn ($v) => $v !== null)->values();
                $last = $vals->last();
                $prev = $vals->count() > 1 ? $vals->get($vals->count() - 2) : null;

                return [
                    'code' => $sec['code'],
                    'name' => $sec['name'],
                    'last' => $last,
                    'previous' => $prev,
                    'change' => $last !== null && $prev !== null ? round($last - $prev, 2) : null,
                    'average' => $vals->isEmpty() ? null : round($vals->avg(), 2),
                    'best' => $vals->isEmpty() ? null : $vals->max(),
                ];
            });

            return [
                'type' => $type,
                'type_name' => $rows->first()->type_name,
                'count' => $rows->count(),
                'points' => $points,
                'subjects' => $subjects,
            ];
        })->values();

        // Konu eksikleri: en az 4 soru sorulmuş, başarı oranı en düşük konular
        $topicRows = DB::table('student_topic_stats as sts')->join('topics as tp', 'tp.id', '=', 'sts.topic_id')
            ->join('subjects as sub', 'sub.id', '=', 'tp.subject_id')
            ->where('sts.student_id', $student->id)->where('sts.asked', '>=', 4)
            ->get(['tp.id', 'tp.name as topic', 'tp.outcome_code', 'sub.name as subject', 'sub.color as subject_color', 'sts.asked', 'sts.correct', 'sts.wrong', 'sts.last_exam_at'])
            ->map(fn ($r) => [...(array) $r, 'rate' => (int) round($r->correct / max(1, $r->asked) * 100)]);

        $weak = $topicRows->sortBy('rate')->filter(fn ($r) => $r['rate'] < 70)->take(10)->values();
        $strong = $topicRows->sortByDesc('rate')->filter(fn ($r) => $r['rate'] >= 80)->take(5)->values();

        $goal = DB::table('student_goals')->where('student_id', $student->id)->where('is_active', true)->latest('id')
            ->first(['university', 'department', 'target_rank', 'target_tyt_net', 'target_ayt_net', 'subject_targets']);

        $upcoming = DB::table('exams as e')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->where('e.branch_id', $student->branch_id)->whereNull('e.deleted_at')->where('e.status', '!=', 'cancelled')
            ->whereBetween('e.exam_date', [now()->toDateString(), now()->addDays(60)->toDateString()])
            ->orderBy('e.exam_date')->limit(8)
            ->get(['e.id', 'e.name', 'e.exam_date', 'e.publisher', 't.code as type', 't.name as type_name']);

        // Aylık devamsızlık özeti (son 6 ay)
        $monthly = DB::table('attendances')->where('student_id', $student->id)->where('date', '>=', now()->subMonths(5)->startOfMonth()->toDateString())
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') AS ym, COUNT(*) AS total, SUM(status IN ('present','late')) AS attended, SUM(status='absent') AS absent, SUM(status='late') AS late, SUM(status IN ('excused','medical')) AS excused")
            ->groupBy('ym')->orderBy('ym')->get()
            ->map(fn ($m) => ['month' => $m->ym, 'total' => (int) $m->total, 'attended' => (int) $m->attended, 'absent' => (int) $m->absent, 'late' => (int) $m->late, 'excused' => (int) $m->excused,
                'rate' => $m->total ? (int) round($m->attended / $m->total * 100) : null]);

        return $this->isoJson([
            'exam_types' => $byType,
            'weak_topics' => $weak,
            'strong_topics' => $strong,
            'goal' => $goal ? [...(array) $goal, 'subject_targets' => $goal->subject_targets ? json_decode($goal->subject_targets, true) : null] : null,
            'upcoming_exams' => $upcoming,
            'attendance_monthly' => $monthly,
        ]);
    }

    // ------------------------------------------------------------------ etütler

    public function study(Request $request): JsonResponse
    {
        $student = $this->student($request);

        $rows = DB::table('study_session_student as sss')->join('study_sessions as ss', 'ss.id', '=', 'sss.study_session_id')
            ->leftJoin('subjects as s', 's.id', '=', 'ss.subject_id')->leftJoin('classrooms as c', 'c.id', '=', 'ss.classroom_id')
            ->leftJoin('teachers as t', 't.id', '=', 'ss.teacher_id')
            ->where('sss.student_id', $student->id)->whereIn('ss.status', ['requested', 'approved', 'completed'])
            ->where('ss.starts_at', '>=', now()->subDays(90))
            ->orderBy('ss.starts_at')
            ->get(['ss.id', 'ss.kind', 'ss.starts_at', 'ss.ends_at', 'ss.topic', 'ss.status', 's.name as subject', 'c.name as classroom',
                DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher"), 'sss.attendance'])
            ->map(fn ($r) => [...(array) $r,
                'kind_label' => $r->kind === 'private' ? 'Birebir ders' : 'Etüt',
                'status_label' => ['requested' => 'Onay bekliyor', 'approved' => 'Onaylandı', 'completed' => 'Tamamlandı'][$r->status] ?? $r->status,
                'attendance_label' => $r->attendance ? (['present' => 'Katıldı', 'absent' => 'Katılmadı', 'late' => 'Geç kaldı', 'excused' => 'İzinli'][$r->attendance] ?? $r->attendance) : null,
            ]);

        $now = now()->toDateTimeString();

        return $this->isoJson([
            'upcoming' => $rows->filter(fn ($r) => $r['ends_at'] >= $now)->values(),
            'past' => $rows->filter(fn ($r) => $r['ends_at'] < $now)->sortByDesc('starts_at')->values(),
            'summary' => [
                'completed' => $rows->where('status', 'completed')->count(),
                'attended' => $rows->whereIn('attendance', ['present', 'late'])->count(),
            ],
        ]);
    }

    // ------------------------------------------------------------------ geri bildirim

    public function feedback(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $isGuardian = (bool) $this->guardian($request);

        $observations = StudentObservation::query()->where('student_id', $student->id)
            ->where($isGuardian ? 'visible_to_guardian' : 'visible_to_student', true)
            ->with(['teacher:id,first_name,last_name,title', 'subject:id,name'])
            ->latest('id')->limit(50)->get()
            ->map(fn (StudentObservation $o) => [
                'id' => $o->id,
                'kind' => $o->kind,
                'kind_label' => StudentObservation::KINDS[$o->kind] ?? $o->kind,
                'category_label' => StudentObservation::CATEGORIES[$o->category] ?? $o->category,
                'points' => $o->points,
                'body' => $o->body,
                'subject' => $o->subject?->name,
                'teacher' => $o->teacher?->full_name,
                'created_at' => $o->created_at?->toAtomString(),
            ]);

        $graded = DB::table('homework_submissions as hs')->join('homework as h', 'h.id', '=', 'hs.homework_id')
            ->join('subjects as s', 's.id', '=', 'h.subject_id')->leftJoin('teachers as t', 't.id', '=', 'h.teacher_id')
            ->where('hs.student_id', $student->id)->whereNull('h.deleted_at')
            ->where(fn ($q) => $q->whereNotNull('hs.score')->orWhereNotNull('hs.teacher_note'))
            ->orderByRaw('COALESCE(hs.graded_at, hs.updated_at) DESC')->limit(40)
            ->get(['hs.id', 'h.title', 'h.due_at', 'hs.status', 'hs.score', 'hs.teacher_note', 'hs.graded_at', 's.name as subject', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")]);

        $bySubject = $graded->whereNotNull('score')->groupBy('subject')->map(fn ($g, $name) => [
            'subject' => $name, 'count' => $g->count(), 'average' => round($g->avg('score'), 1),
        ])->sortByDesc('count')->values();

        return $this->isoJson([
            'observations' => $observations,
            'points_total' => (int) $observations->sum('points'),
            'homework' => $graded,
            'homework_by_subject' => $bySubject,
            'homework_average' => ($avg = $graded->whereNotNull('score')->avg('score')) !== null ? round((float) $avg, 1) : null,
        ]);
    }

    // ------------------------------------------------------------------ öğretmenler + talepler

    /** Öğrencinin şu anki öğretmenleri (ders programından) + rehber öğretmeni. Kişisel telefon DÖNMEZ. */
    public function teachers(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $isGuardian = (bool) $this->guardian($request);

        $rows = $this->studentTeachers($student);
        $institution = Settings::group('institution', $student->branch_id);
        $enabled = (bool) Settings::get('portal.guardian_requests_enabled', true, $student->branch_id);

        $requests = $isGuardian ? ContactRequest::query()->where('student_id', $student->id)->where('user_id', $request->user()->id)
            ->with('teacher:id,first_name,last_name')->latest('id')->limit(30)->get()
            ->map(fn (ContactRequest $r) => [
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
                'teacher' => $r->teacher?->full_name,
            ]) : collect();

        return $this->isoJson([
            'teachers' => $rows->values(),
            'institution' => ['name' => $institution['name'], 'phone' => $institution['phone'], 'email' => $institution['email'], 'address' => $institution['address']],
            'requests_enabled' => $isGuardian && $enabled,
            'requests' => $requests,
            'kinds' => ContactRequest::KINDS,
        ]);
    }

    public function requestStore(Request $request): JsonResponse
    {
        $guardian = $this->guardian($request);
        if (! $guardian) {
            throw new BusinessRuleException('Talep yalnız veli hesabından oluşturulabilir.', 'guardian_only', [], 403);
        }
        $student = $this->student($request);
        if (! Settings::get('portal.guardian_requests_enabled', true, $student->branch_id)) {
            throw new BusinessRuleException('Kurum portal üzerinden talep almıyor. Lütfen kurumu arayın.', 'requests_disabled', [], 403);
        }

        $data = $request->validate([
            'teacher_id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(array_keys(ContactRequest::KINDS))],
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'min:5', 'max:2000'],
            'preferred_times' => ['nullable', 'string', 'max:200'],
        ], [
            'teacher_id.required' => 'Öğretmen seçin.',
            'subject.required' => 'Konu yazın.',
            'body.required' => 'Mesajınızı yazın.',
            'body.min' => 'Mesaj en az :min karakter olmalı.',
        ]);

        if (! $this->studentTeachers($student)->pluck('id')->contains((int) $data['teacher_id'])) {
            throw new BusinessRuleException('Seçilen öğretmen öğrencinin öğretmenleri arasında değil.', 'teacher_not_related', [], 422);
        }

        $open = ContactRequest::query()->where('user_id', $request->user()->id)->where('status', 'open')->count();
        if ($open >= 5) {
            throw new BusinessRuleException('Yanıt bekleyen 5 talebiniz var. Yanıtlandıkça yeni talep oluşturabilirsiniz.', 'too_many_open_requests', [], 422);
        }

        $row = ContactRequest::query()->create([
            'branch_id' => $student->branch_id,
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
            'user_id' => $request->user()->id,
            'teacher_id' => (int) $data['teacher_id'],
            'kind' => $data['kind'],
            'subject' => trim($data['subject']),
            'body' => trim($data['body']),
            'preferred_times' => $data['preferred_times'] ?? null,
            'status' => 'open',
        ]);

        Audit::log('contact_request.created', sprintf('%s için öğretmene %s oluşturdu: "%s".', $student->full_name, mb_strtolower(ContactRequest::KINDS[$row->kind]), mb_substr($row->subject, 0, 80)), $row);
        // Öğretmene yalnız uygulama içi bildirim (SMS/WhatsApp gönderilmez)
        app(\App\Services\Portal\ContactRequestNotifier::class)->created($row);

        return $this->isoJson(['message' => 'Talebiniz öğretmene iletildi. Yanıt bu sayfada görünecek.', 'id' => $row->id], 201);
    }

    /** @return Collection<int, array{id:int, full_name:string, title:?string, subjects:list<string>, is_counselor:bool, is_advisor:bool}> */
    private function studentTeachers(Student $student): Collection
    {
        $groupIds = DB::table('class_group_student as cgs')->join('class_groups as g', 'g.id', '=', 'cgs.class_group_id')
            ->where('cgs.student_id', $student->id)->whereNull('cgs.left_on')->whereNull('g.deleted_at')->where('g.is_active', true)
            ->pluck('g.id');

        $fromSchedule = $groupIds->isEmpty() ? collect() : DB::table('lesson_schedules as ls')->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->whereIn('ls.class_group_id', $groupIds)->whereNull('ls.deleted_at')
            ->where(fn ($q) => $q->whereNull('ls.valid_until')->orWhere('ls.valid_until', '>=', now()->toDateString()))
            ->get(['ls.teacher_id', 's.name as subject'])->groupBy('teacher_id');

        $advisors = $groupIds->isEmpty() ? collect() : DB::table('class_groups')->whereIn('id', $groupIds)->whereNotNull('advisor_teacher_id')->pluck('advisor_teacher_id');

        $ids = $fromSchedule->keys()->merge($advisors)->when($student->guidance_teacher_id, fn ($c) => $c->push($student->guidance_teacher_id))
            ->map(fn ($v) => (int) $v)->unique();

        return $ids->isEmpty() ? collect() : DB::table('teachers')->whereIn('id', $ids)->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'title', 'avatar_path'])
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'full_name' => trim($t->first_name.' '.$t->last_name),
                'title' => $t->title,
                'avatar_url' => $t->avatar_path ? Storage::disk('public')->url($t->avatar_path) : null,
                'subjects' => ($fromSchedule[$t->id] ?? collect())->pluck('subject')->unique()->values()->all(),
                'is_counselor' => (int) $student->guidance_teacher_id === (int) $t->id,
                'is_advisor' => $advisors->contains($t->id),
            ]);
    }

    // ------------------------------------------------------------------ duyuru okundu

    public function announcementRead(Request $request, int $announcement): JsonResponse
    {
        $student = $this->student($request);
        $query = $this->guardian($request)
            ? AnnouncementAudience::forGuardian($student->branch_id, ...PortalController::groupAndProgramIds($student))
            : AnnouncementAudience::forStudent($student->branch_id, ...PortalController::groupAndProgramIds($student));
        abort_unless($query->where('id', $announcement)->exists(), 404);

        AnnouncementAudience::markRead($announcement, $request->user()->id);

        return $this->ok('Okundu olarak işaretlendi.');
    }

    private function docRow(Document $d): array
    {
        return ['id' => $d->id, 'title' => $d->title, 'mime_type' => $d->mime_type, 'size' => (int) $d->size];
    }
}
