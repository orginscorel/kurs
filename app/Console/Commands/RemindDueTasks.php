<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Branch;
use App\Models\Task;
use App\Support\BranchContext;
use Illuminate\Console\Command;

/**
 * Vadesi gelen (ve henüz hatırlatılmamış) görevler için atanan kullanıcıya bildirim oluşturur.
 * CRM aramaları ve rehberlik takip görevlerinin ikisi de bu tek görev tablosunu kullanır.
 */
class RemindDueTasks extends Command
{
    protected $signature = 'kurs:remind-tasks';

    protected $description = 'Vadesi gelen görevler için atanan kullanıcıya bildirim oluşturur';

    public function handle(BranchContext $context): int
    {
        $total = 0;
        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            $total += $context->run($branch->id, function () {
                $n = 0;
                Task::query()->whereNull('completed_at')->whereNull('reminded_at')->whereNotNull('due_at')
                    ->whereNotNull('assigned_to')->where('due_at', '<=', now())
                    ->chunkById(200, function ($tasks) use (&$n) {
                        foreach ($tasks as $task) {
                            AppNotification::query()->create([
                                'user_id' => $task->assigned_to,
                                'type' => 'warning',
                                'title' => 'Görev vadesi geldi',
                                'body' => $task->title,
                                'action_url' => '/gorevlerim',
                                'data' => ['task_id' => $task->id, 'taskable_type' => $task->taskable_type, 'taskable_id' => $task->taskable_id],
                            ]);
                            $task->forceFill(['reminded_at' => now()])->save();
                            $n++;
                        }
                    });

                return $n;
            });
        }

        $this->info("{$total} görev için hatırlatma bildirimi oluşturuldu.");

        return self::SUCCESS;
    }
}
