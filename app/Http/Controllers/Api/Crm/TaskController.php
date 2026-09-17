<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\ApiController;
use App\Models\Guardian;
use App\Models\Lead;
use App\Models\Student;
use App\Models\Task;
use App\Models\User;
use App\Services\Crm\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Görevlerim: CRM aramaları, rehberlik takibi ve genel işler için ortak hatırlatma listesi.
 * Yetki modüle özgü değildir — görev kime atanmışsa o görür; oluşturma taskable türüne göre denetlenir.
 */
class TaskController extends ApiController
{
    public function __construct(private readonly TaskService $tasks) {}

    /** Bana atanan görevler: bugün / geciken / yaklaşan. */
    public function mine(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $today = CarbonImmutable::today();

        $base = Task::query()->where('assigned_to', $userId)->with('taskable');
        $filter = $request->query('filter', 'open');

        $query = match ($filter) {
            'overdue' => (clone $base)->whereNull('completed_at')->where('due_at', '<', $today->startOfDay()),
            'today' => (clone $base)->whereNull('completed_at')->whereBetween('due_at', [$today->startOfDay(), $today->endOfDay()]),
            'upcoming' => (clone $base)->whereNull('completed_at')->where('due_at', '>', $today->endOfDay()),
            'done' => (clone $base)->whereNotNull('completed_at')->orderByDesc('completed_at'),
            default => (clone $base)->whereNull('completed_at'),
        };
        if ($filter !== 'done') {
            $query->orderByRaw('due_at IS NULL')->orderBy('due_at');
        }

        $counts = [
            'overdue' => (clone $base)->whereNull('completed_at')->where('due_at', '<', $today->startOfDay())->count(),
            'today' => (clone $base)->whereNull('completed_at')->whereBetween('due_at', [$today->startOfDay(), $today->endOfDay()])->count(),
            'upcoming' => (clone $base)->whereNull('completed_at')->where('due_at', '>', $today->endOfDay())->count(),
            'open' => (clone $base)->whereNull('completed_at')->count(),
        ];

        return $this->paginated($query->paginate($this->perPage($request, 30)), fn (Task $t) => $this->row($t), ['counts' => $counts]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'taskable_type' => ['nullable', Rule::in(['lead', 'student', 'guardian'])],
            'taskable_id' => ['nullable', 'integer', 'required_with:taskable_type'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])],
        ]);

        $taskable = $this->resolveTaskable($request->user(), $data['taskable_type'] ?? null, $data['taskable_id'] ?? null);
        $task = $this->tasks->create($data, $taskable);

        return response()->json(['message' => 'Görev oluşturuldu.', 'data' => $this->row($task)], 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwner($request, $task);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])],
        ]);
        $task->fill($data)->save();

        return $this->ok('Görev güncellendi.');
    }

    public function complete(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwner($request, $task);
        $this->tasks->complete($task);

        return $this->ok('Görev tamamlandı.');
    }

    public function reopen(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwner($request, $task);
        $this->tasks->reopen($task);

        return $this->ok('Görev yeniden açıldı.');
    }

    public function snooze(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwner($request, $task);
        $data = $request->validate(['due_at' => ['required', 'date']]);
        $this->tasks->snooze($task, $data['due_at']);

        return $this->ok('Görev ertelendi.');
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwner($request, $task);
        $task->delete();

        return $this->ok('Görev silindi.');
    }

    private function authorizeOwner(Request $request, Task $task): void
    {
        $user = $request->user();
        abort_unless($task->assigned_to === $user->id || $task->created_by === $user->id || $user->can('crm.manage') || $user->can('guidance.manage'), 403, 'Bu görev üzerinde işlem yapma yetkiniz yok.');
    }

    private function resolveTaskable(User $user, ?string $type, ?int $id): ?Model
    {
        return match ($type) {
            'lead' => tap(Lead::query()->findOrFail($id), fn () => abort_unless($user->can('crm.manage'), 403, 'Aday görevi oluşturma yetkiniz yok.')),
            'student' => tap(Student::query()->findOrFail($id), fn () => abort_unless($user->can('guidance.manage') || $user->can('students.update') || $user->can('risk.view'), 403, 'Öğrenci görevi oluşturma yetkiniz yok.')),
            'guardian' => Guardian::query()->findOrFail($id),
            default => null,
        };
    }

    private function row(Task $t): array
    {
        $taskable = $t->taskable;
        $label = match (true) {
            $taskable instanceof Lead => $taskable->full_name,
            $taskable instanceof Student => $taskable->full_name,
            $taskable instanceof Guardian => $taskable->full_name,
            default => null,
        };
        $link = match (true) {
            $taskable instanceof Lead => "/crm/adaylar?aday={$taskable->id}",
            $taskable instanceof Student => "/ogrenciler/{$taskable->id}",
            default => null,
        };

        return [
            'id' => $t->id,
            'title' => $t->title,
            'description' => $t->description,
            'due_at' => $t->due_at,
            'priority' => $t->priority,
            'completed_at' => $t->completed_at,
            'taskable_type' => $t->taskable_type,
            'taskable_id' => $t->taskable_id,
            'taskable_label' => $label,
            'taskable_link' => $link,
            'assigned_to' => $t->assigned_to,
        ];
    }
}
