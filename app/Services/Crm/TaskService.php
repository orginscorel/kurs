<?php

namespace App\Services\Crm;

use App\Exceptions\BusinessRuleException;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Görev/hatırlatma: CRM aramaları, rehberlik takibi ve genel işler için ortak model.
 * `taskable` bir Lead, Student ya da Guardian olabilir (bkz. morph map).
 */
class TaskService
{
    public function create(array $data, ?Model $taskable = null): Task
    {
        return DB::transaction(function () use ($data, $taskable) {
            $task = new Task([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? auth()->id(),
                'created_by' => auth()->id(),
                'due_at' => $data['due_at'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
            ]);
            if ($taskable) {
                $task->taskable()->associate($taskable);
            }
            $task->save();

            return $task;
        });
    }

    public function complete(Task $task): Task
    {
        $task->forceFill(['completed_at' => now()])->save();

        return $task;
    }

    public function reopen(Task $task): Task
    {
        $task->forceFill(['completed_at' => null])->save();

        return $task;
    }

    public function snooze(Task $task, string $dueAt): Task
    {
        if (strtotime($dueAt) === false) {
            throw new BusinessRuleException('Geçersiz tarih.', 'invalid_due_at');
        }
        $task->forceFill(['due_at' => $dueAt, 'reminded_at' => null])->save();

        return $task;
    }
}
