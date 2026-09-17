<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Installment;
use App\Models\Student;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\InstallmentReminderPlanner;
use App\Support\BranchContext;
use App\Support\InstitutionFormat;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Taksit hatırlatmaları: kurum ayarındaki ofsetler (varsayılan vadeye 5/2 gün kala, vade günü, 3/7 gün gecikme).
 * Şube başına `finance.reminders_enabled` kapalıysa atlanır; `finance.reminder_hour` saatinden 21:00'e kadar
 * gönderir (zamanlama 5 dk'da bir). Saat kurumun saat dilimine (`institution.timezone`) göredir.
 * `installment_reminders` (installment_id, rule_key) ile aynı kural iki kez tetiklenmez.
 */
class SendInstallmentReminders extends Command
{
    protected $signature = 'kurs:installment-reminders
        {--date= : Bugün yerine bu tarih (YYYY-MM-DD, test)}
        {--now : Saat penceresini beklemeden çalıştır}
        {--dry-run : Yalnız hangi taksitin hangi kuralla eşleştiğini listele; yazma/gönderme yok}';

    protected $description = 'Taksit vade hatırlatmalarını (yaklaşan/bugün/gecikmiş) kurum ayarlarına göre tetikler';

    public function handle(): int
    {
        $sent = 0;

        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            $sent += app(BranchContext::class)->run($branch->id, fn () => $this->runForBranch($branch));
        }

        if ($sent > 0 || $this->option('dry-run')) {
            $this->line("{$sent} taksit hatırlatması ".($this->option('dry-run') ? 'eşleşti (deneme, yazılmadı).' : 'tetiklendi.'));
        }

        return self::SUCCESS;
    }

    private function runForBranch(Branch $branch): int
    {
        $finance = Settings::group('finance', $branch->id);
        if (! filter_var($finance['reminders_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return 0;
        }

        $tz = InstitutionFormat::timezone($branch->id);
        $now = CarbonImmutable::now($tz);
        if ($this->option('date')) {
            $now = CarbonImmutable::parse($this->option('date').' '.$now->format('H:i:s'), $tz);
        } elseif (! $this->option('now') && ! InstallmentReminderPlanner::withinSendWindow($now, $finance['reminder_hour'] ?? '10:00')) {
            return 0;
        }
        $today = $now->startOfDay();

        $rules = InstallmentReminderPlanner::rulesFromOffsets($finance['reminder_offsets'] ?? null);
        $count = 0;

        $installments = Installment::query()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->whereIn('due_date', InstallmentReminderPlanner::candidateDates($today, $rules))
            ->orderBy('id')
            ->get();

        foreach ($installments as $installment) {
            $due = CarbonImmutable::parse(CarbonImmutable::parse($installment->due_date)->toDateString(), $tz);
            $ruleKey = InstallmentReminderPlanner::ruleFor(InstallmentReminderPlanner::daysDiff($today, $due), $rules);
            if (! $ruleKey) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("#{$installment->id} vade {$due->format('d.m.Y')} → {$ruleKey}");
                $count++;

                continue;
            }

            $student = Student::query()->find($installment->student_id);
            if (! $student) {
                continue;
            }

            // Tekil (installment_id, rule_key): aynı kural bu taksit için yalnız bir kez.
            $reserved = DB::table('installment_reminders')->insertOrIgnore([
                'installment_id' => $installment->id, 'rule_key' => $ruleKey, 'created_at' => now(),
            ]);
            if ($reserved === 0) {
                continue;
            }

            AutomationEngine::fire(InstallmentReminderPlanner::triggerFor($ruleKey), $student, [
                'vade_tarihi' => $due->format('d.m.Y'),
                'tutar' => number_format((float) $installment->remaining(), 2, ',', '.'),
                'gecikme_gun' => str_starts_with($ruleKey, 'after_') ? (string) InstallmentReminderPlanner::daysFromRuleKey($ruleKey) : '0',
            ], [
                'days_offset' => InstallmentReminderPlanner::daysFromRuleKey($ruleKey),
                'dedupe_suffix' => "installment:{$installment->id}:{$ruleKey}",
            ]);
            $count++;
        }

        return $count;
    }
}
