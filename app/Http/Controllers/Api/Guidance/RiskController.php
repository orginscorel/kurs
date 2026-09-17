<?php

namespace App\Http\Controllers\Api\Guidance;

use App\Http\Controllers\Api\ApiController;
use App\Models\GuidanceMeeting;
use App\Models\Student;
use App\Models\StudentRiskScore;
use App\Models\User;
use App\Services\Crm\TaskService;
use App\Services\Students\StudentInsights;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RiskController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canFinance = $user->can('finance.view');

        $query = Student::query()->with(['riskScore', 'currentClassGroups:id,name'])->select('students.*')
            ->join('student_risk_scores as srs', 'srs.student_id', '=', 'students.id');

        if ($level = $request->query('level')) {
            $query->where('srs.level', $level);
        } else {
            $query->where('srs.level', '!=', 'low');
        }
        if ($classGroupId = $request->integer('class_group_id')) {
            $query->whereExists(fn (Builder $s) => $s->from('class_group_student')->whereColumn('student_id', 'students.id')->where('class_group_id', $classGroupId)->whereNull('left_on'));
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->where('students.full_name', 'like', '%'.$q.'%');
        }
        $query->orderByDesc('srs.score');

        $paginator = $query->paginate($this->perPage($request));

        return $this->paginated($paginator, fn (Student $s) => $this->row($s, $canFinance));
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'levels' => ['high' => 'Yüksek risk', 'medium' => 'Orta risk', 'low' => 'Düşük risk'],
            'class_groups' => \App\Models\ClassGroup::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'counselors' => User::query()->where('is_active', true)->whereIn('user_type', ['staff', 'teacher'])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function recalculate(Student $student, StudentInsights $insights): JsonResponse
    {
        $score = $insights->riskFor($student, refresh: true);

        return response()->json(['message' => 'Risk puanı yeniden hesaplandı.', 'data' => [
            'score' => $score->score, 'level' => $score->level, 'calculated_at' => $score->calculated_at,
        ]]);
    }

    /** Riskli öğrenci kartından hızlı aksiyon: rehberlik görüşmesi planla (görev + yaklaşan görüşme alanı). */
    public function planMeeting(Request $request, Student $student, TaskService $tasks): JsonResponse
    {
        abort_unless($request->user()->can('guidance.manage'), 403);
        $data = $request->validate([
            'due_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ], [], ['due_at' => 'Görüşme tarihi', 'note' => 'Not', 'assigned_to' => 'Görevlendirilen personel']);

        $task = $tasks->create([
            'title' => "Rehberlik görüşmesi planla: {$student->full_name}",
            'description' => $data['note'] ?? null,
            'due_at' => $data['due_at'],
            'assigned_to' => $data['assigned_to'] ?? $request->user()->id,
            'priority' => 'high',
        ], $student);

        // Öğrencinin en güncel görüşmesinde henüz bir sonraki görüşme tarihi işaretlenmemişse, planlanan tarihi orada da göster.
        $latest = GuidanceMeeting::query()->where('student_id', $student->id)->whereNull('next_meeting_on')->orderByDesc('met_at')->first();
        $latest?->forceFill(['next_meeting_on' => $data['due_at']])->save();

        return response()->json(['message' => 'Görüşme planlandı ve göreve eklendi.', 'task_id' => $task->id], 201);
    }

    private function row(Student $s, bool $canFinance): array
    {
        /** @var StudentRiskScore $risk */
        $risk = $s->riskScore;
        $factors = collect($risk->factors)->reject(fn ($f) => ! $canFinance && ! empty($f['finance']))->values();

        return [
            'id' => $s->id,
            'full_name' => $s->full_name,
            'student_no' => $s->student_no,
            'school_grade' => $s->school_grade,
            'class_groups' => $s->relationLoaded('currentClassGroups') ? $s->currentClassGroups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name]) : [],
            'score' => $risk->score,
            'level' => $risk->level,
            'factors' => $factors,
            'insights' => $risk->insights,
            'calculated_at' => $risk->calculated_at,
        ];
    }
}
