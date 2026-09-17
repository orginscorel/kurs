<?php

namespace App\Http\Controllers\Api\Guidance;

use App\Http\Controllers\Api\ApiController;
use App\Models\Student;
use App\Models\StudentGoal;
use App\Services\Guidance\GoalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentGoalController extends ApiController
{
    public function __construct(private readonly GoalService $goals) {}

    /** Hedef takibi ekranı: kurum genelinde aktif hedefler + ilerleme. */
    public function index(Request $request): JsonResponse
    {
        $query = StudentGoal::query()->where('is_active', true)->with('student:id,full_name,student_no,school_grade');

        if ($classGroupId = $request->integer('class_group_id')) {
            $query->whereHas('student', fn (Builder $s) => $s->whereExists(fn ($e) => $e->from('class_group_student')
                ->whereColumn('student_id', 'students.id')->where('class_group_id', $classGroupId)->whereNull('left_on')));
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->whereHas('student', fn (Builder $s) => $s->where('full_name', 'like', '%'.$q.'%'));
        }

        $paginator = $query->orderByDesc('id')->paginate($this->perPage($request));

        return $this->paginated($paginator, fn (StudentGoal $g) => $this->row($g, withProgress: true));
    }

    /** Hedef takibi filtreleri: yalnız etkin sınıflar (risk seçenekleri ayrı ve risk.view ister). */
    public function options(): JsonResponse
    {
        return response()->json([
            'class_groups' => \App\Models\ClassGroup::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function forStudent(Student $student): JsonResponse
    {
        $goals = $student->goals()->orderByDesc('is_active')->orderByDesc('id')->get();

        return response()->json(['data' => $goals->map(fn ($g) => $this->row($g, withProgress: true))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')],
            ...$this->rules(),
        ], [], $this->names());
        $student = Student::query()->findOrFail($data['student_id']);
        $goal = $this->goals->create($student, $data);

        return response()->json(['message' => 'Hedef eklendi.', 'id' => $goal->id], 201);
    }

    public function update(Request $request, StudentGoal $goal): JsonResponse
    {
        $data = $request->validate($this->rules(), [], $this->names());
        $this->goals->update($goal, $data);

        return $this->ok('Hedef güncellendi.');
    }

    public function destroy(StudentGoal $goal): JsonResponse
    {
        $this->goals->delete($goal);

        return $this->ok('Hedef silindi.');
    }

    /** @return array<string, string> */
    private function names(): array
    {
        return [
            'student_id' => 'Öğrenci', 'university' => 'Hedef üniversite', 'department' => 'Hedef bölüm', 'target_rank' => 'Hedef başarı sırası',
            'target_tyt_net' => 'Hedef TYT neti', 'target_ayt_net' => 'Hedef AYT neti', 'subject_targets' => 'Ders hedefleri', 'subject_targets.*' => 'Ders hedef neti',
            'is_active' => 'Durum',
        ];
    }

    private function rules(): array
    {
        return [
            'university' => ['nullable', 'string', 'max:160'],
            'department' => ['nullable', 'string', 'max:160'],
            'target_rank' => ['nullable', 'integer', 'min:1'],
            'target_tyt_net' => ['nullable', 'numeric', 'min:0', 'max:120'],
            'target_ayt_net' => ['nullable', 'numeric', 'min:0', 'max:80'],
            'subject_targets' => ['nullable', 'array'],
            'subject_targets.*' => ['numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    private function row(StudentGoal $g, bool $withProgress = false): array
    {
        return [
            'id' => $g->id,
            'student' => $g->relationLoaded('student') ? $g->student?->only(['id', 'full_name', 'student_no', 'school_grade']) : null,
            'university' => $g->university,
            'department' => $g->department,
            'target_rank' => $g->target_rank,
            'target_tyt_net' => $g->target_tyt_net,
            'target_ayt_net' => $g->target_ayt_net,
            'subject_targets' => $g->subject_targets,
            'is_active' => $g->is_active,
            'progress' => $withProgress ? $this->goals->progress($g) : null,
        ];
    }
}
