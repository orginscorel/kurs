<?php

namespace App\Http\Controllers\Api\Academic;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Document;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Teacher;
use App\Services\Academic\HomeworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HomeworkController extends ApiController
{
    public function __construct(private readonly HomeworkService $homework) {}

    public function index(Request $request): JsonResponse
    {
        $query = Homework::query()->with(['teacher:id,first_name,last_name', 'subject:id,name,color', 'classGroup:id,name', 'topic:id,name'])
            ->withCount([
                'submissions as total_count',
                'submissions as done_count' => fn ($q) => $q->whereIn('status', ['submitted', 'late']),
                'submissions as missed_count' => fn ($q) => $q->where('status', 'missed'),
                'submissions as graded_count' => fn ($q) => $q->whereNotNull('score'),
            ])
            ->when($request->query('q'), fn ($q, $s) => $q->where('title', 'like', "%$s%"))
            ->when($request->integer('class_group_id'), fn ($q, $id) => $q->where('class_group_id', $id))
            ->when($request->integer('subject_id'), fn ($q, $id) => $q->where('subject_id', $id))
            ->when($request->integer('teacher_id'), fn ($q, $id) => $q->where('teacher_id', $id))
            ->when($request->query('status', 'open'), fn ($q, $s) => match ($s) {
                'open' => $q->where('due_at', '>', now()),
                'closed' => $q->where('due_at', '<=', now()),
                default => $q,
            });
        $this->applyDateRange($query, $request, 'due_at');
        $this->applySort($query, $request, ['due_at' => 'due_at', 'assigned_at' => 'assigned_at', 'title' => 'title', 'done_count' => 'done_count'], '-due_at');

        $counts = ['open' => Homework::query()->where('due_at', '>', now())->count(), 'closed' => Homework::query()->where('due_at', '<=', now())->count()];

        return $this->paginated($query->paginate($this->perPage($request, 30)), fn (Homework $h) => $this->row($h), ['status_counts' => $counts, 'statuses' => HomeworkSubmission::STATUSES]);
    }

    public function show(Homework $homework): JsonResponse
    {
        $homework->load(['teacher:id,first_name,last_name', 'subject:id,name,color', 'classGroup:id,name', 'topic:id,name,outcome_code'])
            ->loadCount(['submissions as total_count', 'submissions as done_count' => fn ($q) => $q->whereIn('status', ['submitted', 'late']), 'submissions as missed_count' => fn ($q) => $q->where('status', 'missed'), 'submissions as graded_count' => fn ($q) => $q->whereNotNull('score')]);

        $subs = $homework->submissions()->with(['student:id,student_no,full_name,photo_path', 'student.currentClassGroups:id,name'])->get()
            ->sortBy(fn ($s) => $s->student->full_name)->values()
            ->map(fn (HomeworkSubmission $s) => [
                'id' => $s->id, 'student_id' => $s->student_id, 'student_no' => $s->student->student_no, 'full_name' => $s->student->full_name,
                'photo_url' => $s->student->photo_path ? Storage::disk('public')->url($s->student->photo_path) : null,
                'class_group' => $s->student->currentClassGroups->pluck('name')->join(', '),
                'status' => $s->status, 'status_label' => HomeworkSubmission::STATUSES[$s->status] ?? $s->status,
                'seen_at' => $s->seen_at?->toIso8601String(), 'submitted_at' => $s->submitted_at?->toIso8601String(), 'score' => $s->score, 'teacher_note' => $s->teacher_note,
            ]);

        return response()->json([
            'homework' => [...$this->row($homework), 'description' => $homework->description],
            'submissions' => $subs,
            'documents' => $this->homework->documents($homework)->get()->map(fn (Document $d) => ['id' => $d->id, 'title' => $d->title, 'mime_type' => $d->mime_type, 'size' => $d->size, 'created_at' => $d->created_at?->toIso8601String()]),
            'stats' => $subs->groupBy('status')->map->count(),
            'avg_score' => $subs->whereNotNull('score')->avg('score') !== null ? number_format($subs->whereNotNull('score')->avg('score'), 1, '.', '') : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['teacher_id'] = $this->resolveTeacher($request, $data['teacher_id'] ?? null);
        $hw = $this->homework->create($data, $request->user());

        return response()->json(['message' => 'Ödev verildi.', 'id' => $hw->id], 201);
    }

    public function update(Request $request, Homework $homework): JsonResponse
    {
        $this->assertOwnerOrManager($request, $homework);
        $this->homework->update($homework, $this->validated($request, partial: true));

        return $this->ok('Ödev güncellendi.');
    }

    public function destroy(Request $request, Homework $homework): JsonResponse
    {
        $this->assertOwnerOrManager($request, $homework);
        $this->homework->delete($homework);

        return $this->ok('Ödev silindi.');
    }

    public function grade(Request $request, Homework $homework): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.student_id' => ['required', 'integer'],
            'rows.*.status' => ['nullable', Rule::in(array_keys(HomeworkSubmission::STATUSES))],
            'rows.*.score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'rows.*.teacher_note' => ['nullable', 'string', 'max:1000'],
        ], [], ['rows' => 'Teslim listesi', 'rows.*.status' => 'Teslim durumu', 'rows.*.score' => 'Puan', 'rows.*.teacher_note' => 'Öğretmen notu']);
        $n = $this->homework->grade($homework, $data['rows']);

        return $this->ok("{$n} öğrenci değerlendirildi.", ['updated' => $n]);
    }

    public function upload(Request $request, Homework $homework): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:'.HomeworkService::MAX_FILE_KB]], ['file.max' => 'Dosya en fazla 15 MB olabilir.']);
        $doc = $this->homework->attach($homework, $request->file('file'), $request->user());

        return response()->json(['message' => 'Dosya eklendi.', 'data' => ['id' => $doc->id, 'title' => $doc->title, 'mime_type' => $doc->mime_type, 'size' => $doc->size]], 201);
    }

    public function download(Homework $homework, Document $document): StreamedResponse
    {
        if ($document->documentable_type !== 'homework' || (int) $document->documentable_id !== $homework->id) {
            abort(404);
        }
        if (! Storage::disk($document->disk)->exists($document->path)) {
            throw new BusinessRuleException('Dosya sunucuda bulunamadı.', 'file_missing', [], 404);
        }

        return Storage::disk($document->disk)->download($document->path, $document->title);
    }

    public function deleteDocument(Request $request, Homework $homework, Document $document): JsonResponse
    {
        $this->assertOwnerOrManager($request, $homework);
        $this->homework->detach($homework, $document);

        return $this->ok('Dosya silindi.');
    }

    /** Öğrenci teslim bildirimi (öğrenci portalı / mobil): kendi ödevi için. */
    public function submit(Request $request, Homework $homework): JsonResponse
    {
        $studentId = DB::table('students')->where('user_id', $request->user()->id)->value('id');
        abort_unless($studentId, 403);
        $sub = $this->homework->submit($homework, (int) $studentId);

        return $this->ok($sub->status === 'late' ? 'Ödev geç teslim edildi.' : 'Ödev teslim edildi.', ['status' => $sub->status]);
    }

    // ------------------------------------------------------------------ yardımcılar

    private function row(Homework $h): array
    {
        $total = (int) ($h->total_count ?? 0);

        return [
            'id' => $h->id, 'title' => $h->title, 'assigned_at' => $h->assigned_at?->toIso8601String(), 'due_at' => $h->due_at->toIso8601String(),
            'is_open' => $h->due_at->isFuture(), 'teacher_id' => $h->teacher_id, 'subject_id' => $h->subject_id, 'class_group_id' => $h->class_group_id, 'topic_id' => $h->topic_id,
            'teacher' => $h->teacher?->full_name, 'subject' => $h->subject ? ['id' => $h->subject->id, 'name' => $h->subject->name, 'color' => $h->subject->color] : null,
            'class_group' => $h->classGroup?->name, 'topic' => $h->topic?->name,
            'total_count' => $total, 'done_count' => (int) ($h->done_count ?? 0), 'missed_count' => (int) ($h->missed_count ?? 0), 'graded_count' => (int) ($h->graded_count ?? 0),
            'completion' => $total ? round(((int) ($h->done_count ?? 0)) / $total * 100) : 0,
        ];
    }

    private function resolveTeacher(Request $request, ?int $teacherId): int
    {
        $user = $request->user();
        $own = Teacher::query()->where('user_id', $user->id)->value('id');
        if ($own && ($user->user_type === 'teacher' || ! $teacherId)) {
            return (int) $own;
        }
        if (! $teacherId) {
            throw \Illuminate\Validation\ValidationException::withMessages(['teacher_id' => 'Ödevi veren öğretmeni seçin.']);
        }

        return $teacherId;
    }

    /** Öğretmen yalnızca kendi ödevini düzenler; yönetici (academic.manage) hepsini. */
    private function assertOwnerOrManager(Request $request, Homework $homework): void
    {
        $user = $request->user();
        if ($user->can('academic.manage')) {
            return;
        }
        $own = Teacher::query()->where('user_id', $user->id)->value('id');
        if ($own && (int) $own === $homework->teacher_id) {
            return;
        }
        throw new BusinessRuleException('Yalnızca ödevi veren öğretmen ya da yönetici bu işlemi yapabilir.', 'not_owner', [], 403);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')],
            'subject_id' => [$req, 'integer', Rule::exists('subjects', 'id')],
            'class_group_id' => [$partial ? 'nullable' : 'required_without:student_ids', 'nullable', 'integer', Rule::exists('class_groups', 'id')],
            'student_ids' => [$partial ? 'nullable' : 'required_without:class_group_id', 'nullable', 'array', 'max:500'], 'student_ids.*' => ['integer', Rule::exists('students', 'id')],
            'topic_id' => ['nullable', 'integer', Rule::exists('topics', 'id')],
            'title' => [$req, 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_at' => [$req, 'date'],
        ], [
            'class_group_id.required_without' => 'Ödevin verileceği sınıfı seçin.',
            'student_ids.required_without' => 'En az bir öğrenci seçin.',
        ], [
            'teacher_id' => 'Veren öğretmen', 'subject_id' => 'Ders', 'class_group_id' => 'Sınıf', 'student_ids' => 'Öğrenciler', 'student_ids.*' => 'Öğrenci',
            'topic_id' => 'Konu', 'title' => 'Ödev başlığı', 'description' => 'Açıklama', 'due_at' => 'Son teslim tarihi',
        ]);
    }
}
