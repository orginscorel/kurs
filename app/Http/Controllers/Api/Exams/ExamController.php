<?php

namespace App\Http\Controllers\Api\Exams;

use App\Http\Controllers\Api\ApiController;
use App\Models\Exam;
use App\Models\ExamType;
use App\Models\Subject;
use App\Services\Exams\ExamAnalytics;
use App\Services\Exams\ExamResultService;
use App\Services\Exams\ExamService;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExamController extends ApiController
{
    use Concerns\TurkishValidation;

    public function __construct(private readonly ExamService $exams, private readonly ExamAnalytics $analytics) {}

    public function index(Request $request): JsonResponse
    {
        $query = Exam::query()->with('type:id,code,name')
            ->select('exams.*')
            ->addSelect([
                'avg_net' => DB::table('exam_results')->selectRaw('ROUND(AVG(net), 2)')->whereColumn('exam_id', 'exams.id'),
                'result_count' => DB::table('exam_results')->selectRaw('COUNT(*)')->whereColumn('exam_id', 'exams.id'),
                'question_total' => DB::table('exam_sections')->selectRaw('COALESCE(SUM(question_count), 0)')->whereColumn('exam_id', 'exams.id'),
                'key_count' => DB::table('exam_questions')->join('exam_sections', 'exam_sections.id', '=', 'exam_questions.exam_section_id')->selectRaw('COUNT(*)')->whereColumn('exam_sections.exam_id', 'exams.id'),
                'max_net' => DB::table('exam_results')->selectRaw('MAX(net)')->whereColumn('exam_id', 'exams.id'),
                'term_name' => DB::table('academic_terms')->select('name')->whereColumn('id', 'exams.academic_term_id')->limit(1),
            ]);

        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('publisher', 'like', "%{$q}%"));
        }
        if ($status = $request->query('status')) {
            $status === 'upcoming' ? $query->where('status', '!=', 'results_published') : $query->where('status', $status);
        }
        if ($typeId = $request->integer('exam_type_id')) {
            $query->where('exam_type_id', $typeId);
        }
        if ($scope = $request->query('scope')) {
            $query->where('scope', $scope);
        }
        $this->applyDateRange($query, $request, 'exam_date');
        $this->applySort($query, $request, ['exam_date' => 'exam_date', 'name' => 'name', 'participant_count' => 'participant_count', 'avg_net' => 'avg_net', 'created_at' => 'created_at'], '-exam_date');

        $counts = Exam::query()->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        return $this->paginated($query->paginate($this->perPage($request)), fn (Exam $e) => $this->row($e), ['status_counts' => $counts]);
    }

    public function options(): JsonResponse
    {
        $branchId = app(BranchContext::class)->id();

        return response()->json([
            'types' => ExamType::query()->where('is_active', true)->orderBy('id')->get(['id', 'code', 'name', 'wrong_penalty_ratio', 'base_score', 'sections']),
            'class_groups' => DB::table('class_groups')->where('branch_id', $branchId)->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'program_id']),
            'subjects' => Subject::query()->where('is_active', true)->orderBy('name')->with('topics:id,subject_id,parent_id,name,outcome_code,sort')->get(['id', 'code', 'name', 'short_name', 'color'])
                ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'short_name' => $s->short_name, 'color' => $s->color, 'topics' => $s->topics->map(fn ($t) => ['id' => $t->id, 'parent_id' => $t->parent_id, 'name' => $t->name, 'outcome_code' => $t->outcome_code])]),
            'publishers' => Exam::query()->whereNotNull('publisher')->distinct()->orderBy('publisher')->pluck('publisher'),
            'terms' => DB::table('academic_terms')->where('branch_id', $branchId)->orderByDesc('starts_on')->get(['id', 'name', 'is_current']),
            'statuses' => self::STATUSES,
        ]);
    }

    public const STATUSES = ['draft' => 'Taslak', 'answer_key_ready' => 'Anahtar hazır', 'results_published' => 'Yayımlandı'];

    /**
     * Deneme öğrencileri: denemeye girecek öğrenciler kayıt oldukları paketten gelir.
     * Kapsam = aktif enrollment'ı "deneme sistemi dahil" (has_exams) paketli öğrenciler
     * (ekstra deneme paketi de has_exams'lı bir pakettir). Sınıf/program süzgeci opsiyonel.
     */
    public function denemeStudents(Request $request): JsonResponse
    {
        $examEnrollment = fn ($e) => $e->whereIn('status', ['active', 'pending', 'frozen'])
            ->whereHas('package', fn ($p) => $p->where('has_exams', true));

        $query = \App\Models\Student::query()
            ->whereIn('status', ['active', 'enrolled', 'frozen'])
            ->whereHas('enrollments', $examEnrollment)
            ->with(['enrollments' => fn ($e) => $examEnrollment($e)->with('package:id,name,has_exams')->orderBy('enrolled_on')->limit(1)])
            ->select('id', 'full_name', 'student_no', 'school_grade', 'field')
            ->when($request->integer('class_group_id'), fn ($q, $id) => $q->whereHas('classGroups', fn ($g) => $g->where('class_groups.id', $id)))
            ->when(trim((string) $request->query('q')), fn ($q, $s) => $q->where(fn ($w) => $w->where('full_name', 'like', "%$s%")->orWhere('student_no', 'like', "%$s%")));

        $paginator = $query->orderBy('full_name')->paginate($this->perPage($request));

        return $this->paginated($paginator, function (\App\Models\Student $s) {
            $enr = $s->enrollments->first();

            return [
                'id' => $s->id,
                'full_name' => $s->full_name,
                'student_no' => $s->student_no,
                'school_grade' => $s->school_grade,
                'field' => $s->field,
                'package' => $enr?->package?->name,
                'start' => $enr?->enrolled_on?->toDateString(),
                'end' => $enr?->ended_on?->toDateString(),
            ];
        });
    }

    public function store(Request $request): JsonResponse
    {
        $exam = $this->exams->create($this->validated($request));

        return response()->json(['message' => 'Deneme oluşturuldu. Şimdi cevap anahtarını girebilirsiniz.', 'id' => $exam->id], 201);
    }

    public function show(Exam $exam): JsonResponse
    {
        $this->exams->ensureSections($exam);
        $exam->load(['type:id,code,name', 'sections' => fn ($q) => $q->withCount('questions'), 'creator:id,name']);
        $keyStats = DB::table('exam_questions')->join('exam_sections', 'exam_sections.id', '=', 'exam_questions.exam_section_id')->where('exam_sections.exam_id', $exam->id)
            ->selectRaw('COUNT(*) AS total, SUM(is_cancelled) AS cancelled, SUM(topic_id IS NOT NULL) AS with_topic')->first();

        return response()->json([
            'exam' => $this->row($exam) + [
                'wrong_penalty_ratio' => (float) $exam->wrong_penalty_ratio, 'base_score' => (float) $exam->base_score,
                'academic_term_id' => $exam->academic_term_id, 'created_by' => $exam->creator?->name, 'created_at' => $exam->created_at?->toIso8601String(),
                'sections' => $exam->sections->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'subject_id' => $s->subject_id, 'question_count' => (int) $s->question_count, 'coefficient' => (float) $s->coefficient, 'questions_count' => $s->questions_count]),
                'key' => ['total' => (int) $keyStats->total, 'cancelled' => (int) $keyStats->cancelled, 'with_topic' => (int) $keyStats->with_topic, 'expected' => (int) $exam->sections->sum('question_count')],
            ],
            'overview' => $this->analytics->overview($exam),
            'imports' => $exam->imports()->with('creator:id,name')->latest()->limit(10)->get()->map(fn ($i) => app(\App\Services\Exams\Optical\OpticalImportService::class)->serialize($i)),
        ]);
    }

    public function update(Request $request, Exam $exam): JsonResponse
    {
        $this->exams->update($exam, $this->validated($request, $exam));

        return $this->ok('Deneme güncellendi.');
    }

    public function destroy(Exam $exam): JsonResponse
    {
        $this->exams->delete($exam);

        return $this->ok('Deneme silindi.');
    }

    public function publish(Exam $exam, ExamResultService $results): JsonResponse
    {
        $results->publish($exam);

        return $this->ok("{$exam->name} sonuçları yayımlandı ({$exam->participant_count} öğrenci).");
    }

    public function recalculate(Exam $exam): JsonResponse
    {
        $n = $this->exams->recalculate($exam);

        return $this->ok("{$n} öğrencinin sonucu yeniden hesaplandı; sıralamalar güncellendi.");
    }

    /** Akademik Komuta Merkezi */
    public function dashboard(): JsonResponse
    {
        return response()->json($this->analytics->dashboard());
    }

    /** Kümülatif konu/kazanım analizi */
    public function topics(Request $request): JsonResponse
    {
        return response()->json($this->analytics->cumulativeTopics([
            'class_group_id' => $request->integer('class_group_id') ?: null,
            'student_id' => $request->integer('student_id') ?: null,
            'subject_id' => $request->integer('subject_id') ?: null,
            'min_asked' => $request->integer('min_asked') ?: 3,
        ]));
    }

    // ------------------------------------------------------------------ yardımcılar

    private function row(Exam $e): array
    {
        return [
            'id' => $e->id, 'name' => $e->name, 'publisher' => $e->publisher, 'scope' => $e->scope, 'exam_date' => $e->exam_date->toDateString(),
            'status' => $e->status, 'status_label' => self::STATUSES[$e->status] ?? $e->status, 'published_at' => $e->published_at?->toIso8601String(),
            'booklets' => $e->booklets ?? ['A'], 'participant_count' => (int) $e->participant_count,
            'type' => $e->type ? ['id' => $e->type->id, 'code' => $e->type->code, 'name' => $e->type->name] : null,
            'avg_net' => isset($e->avg_net) ? (float) $e->avg_net : null,
            'result_count' => isset($e->result_count) ? (int) $e->result_count : null,
            'question_total' => isset($e->question_total) ? (int) $e->question_total : null,
            'key_count' => isset($e->key_count) ? (int) $e->key_count : null,
            'max_net' => isset($e->max_net) ? (float) $e->max_net : null,
            'term' => $e->term_name ?? null,
        ];
    }

    private function validated(Request $request, ?Exam $exam = null): array
    {
        return $request->validate([
            'exam_type_id' => [$exam ? 'prohibited' : 'required', 'integer', Rule::exists('exam_types', 'id')],
            'name' => ['required', 'string', 'max:160'],
            'exam_date' => ['required', 'date'],
            'publisher' => ['nullable', 'string', 'max:120'],
            'scope' => ['nullable', Rule::in(['institution', 'national'])],
            'academic_term_id' => ['nullable', 'integer'],
            'booklets' => ['nullable', 'array', 'min:1', 'max:4'], 'booklets.*' => ['string', 'max:2'],
            'wrong_penalty_ratio' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'base_score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'sections' => ['sometimes', 'array', 'min:1', 'max:12'],
            'sections.*.code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'sections.*.name' => ['required', 'string', 'max:80'],
            'sections.*.subject_id' => ['nullable', 'integer'],
            'sections.*.subject_code' => ['nullable', 'string', 'max:20'],
            'sections.*.question_count' => ['required', 'integer', 'min:1', 'max:120'],
            'sections.*.coefficient' => ['required', 'numeric', 'min:0', 'max:20'],
        ], $this->messages([
            'sections.*.code.regex' => 'Bölüm kodu yalnızca harf, rakam ve alt çizgi içerebilir.',
        ]), $this->attributes());
    }
}
