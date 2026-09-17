<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Installment;
use App\Models\InstallmentReminder;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\BranchContext;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Gece `kurs:mark-overdue` (00:05) sonrasında yeni GECİKTİ olan taksitler için webhook `payment.overdue`.
 * Tek sefer: `installment_reminders` (installment_id, rule_key='webhook_overdue') tekil indeksi.
 * Yalnız son 7 gün içinde vadesi geçenler (ilk kurulumda eski gecikmeler topluca gönderilmez).
 */
class DispatchOverdueInstallmentWebhooks extends Command
{
    public const RULE_KEY = 'webhook_overdue';

    protected $signature = 'kurs:overdue-installment-webhooks {--student= : Yalnız bu öğrenci (test)}';

    protected $description = 'Yeni gecikmiş taksitler için payment.overdue webhook olayını bir kez gönderir';

    public function handle(WebhookDispatcher $webhooks): int
    {
        $total = 0;
        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            $total += app(BranchContext::class)->run($branch->id, function () use ($webhooks, $branch) {
                $n = 0;
                Installment::query()->where('status', 'overdue')
                    ->whereBetween('due_date', [now()->subDays(7)->toDateString(), now()->subDay()->toDateString()])
                    ->when($this->option('student'), fn ($q, $id) => $q->where('student_id', (int) $id))
                    ->whereNotIn('id', InstallmentReminder::query()->where('rule_key', self::RULE_KEY)->select('installment_id'))
                    ->chunkById(200, function ($rows) use ($webhooks, $branch, &$n) {
                        foreach ($rows as $inst) {
                            try {
                                InstallmentReminder::query()->create(['installment_id' => $inst->id, 'rule_key' => self::RULE_KEY, 'created_at' => now()]);
                            } catch (QueryException) {
                                continue;
                            }
                            $webhooks->dispatch('payment.overdue', [
                                'installment_id' => $inst->id, 'enrollment_id' => $inst->enrollment_id, 'student_id' => $inst->student_id,
                                'due_date' => \Carbon\Carbon::parse($inst->due_date)->toDateString(), 'amount' => (string) $inst->amount,
                                'remaining' => $inst->remaining(),
                            ], $branch->id);
                            $n++;
                        }
                    });

                return $n;
            });
        }
        $this->line("{$total} gecikmiş taksit için webhook olayı üretildi.");

        return self::SUCCESS;
    }
}
