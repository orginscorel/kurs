<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Lead;
use App\Services\Notifications\NotificationService;
use App\Support\BranchContext;
use Illuminate\Console\Command;

/** CRM: adayın "sonraki aksiyon" zamanı geldi → aday sorumlusuna uygulama bildirimi (aksiyon zamanı başına tek sefer). */
class RemindLeadNextActions extends Command
{
    protected $signature = 'kurs:lead-next-action-reminders {--lead= : Yalnız bu aday (test)}';

    protected $description = 'Sonraki aksiyon zamanı gelen adaylar için sorumluya bildirim oluşturur';

    public function handle(NotificationService $notifications): int
    {
        $total = 0;
        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            $total += app(BranchContext::class)->run($branch->id, function () use ($notifications) {
                $n = 0;
                Lead::query()->whereNotNull('owner_id')->whereNotNull('next_action_at')->whereNull('student_id')
                    ->whereNotIn('stage', ['won', 'lost'])
                    ->when($this->option('lead'), fn ($q, $id) => $q->whereKey((int) $id))
                    ->where('next_action_at', '<=', now())->where('next_action_at', '>=', now()->subDay())
                    ->chunkById(200, function ($leads) use ($notifications, &$n) {
                        foreach ($leads as $lead) {
                            $n += $notifications->notify((int) $lead->owner_id, 'warning', "Aday aksiyonu: {$lead->full_name}",
                                trim(($lead->next_action ?: 'Planlanan aksiyonun zamanı geldi.').' · '.(Lead::STAGES[$lead->stage] ?? $lead->stage)),
                                '/crm/adaylar',
                                ['kind' => 'lead_next_action', 'lead_id' => $lead->id, 'once' => "lead_next_action:{$lead->id}:".$lead->next_action_at->format('YmdHi')]);
                        }
                    });

                return $n;
            });
        }
        if ($total > 0) {
            $this->line("{$total} aday aksiyon bildirimi oluşturuldu.");
        }

        return self::SUCCESS;
    }
}
