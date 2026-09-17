<?php

namespace App\Services\Automation;

use App\Jobs\RunAutomationAction;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Student;
use App\Support\BranchContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Otomasyon motorunun tek girişi. Bir dinleyici ya da zamanlanmış komut bir olay
 * tetiklediğinde `fire()` çağrılır: ilgili şubenin AKTİF kurallarını bulur, koşulları
 * denetler, gecikme varsa erteler, `automation_runs` ile aynı olayı iki kez işlemez.
 *
 * Odak her zaman bir öğrencidir; sınıf/ders bazlı tetikleyicilerde (ör. yarınki program,
 * ödev hatırlatma) çağıran taraf öğrenci başına ayrı `fire()` çağırır.
 *
 * Ortak kapılar (tüm tetikleyiciler için tek yerde):
 *  - `kurs.silent_events` → hiçbir şey üretilmez (demo seed).
 *  - Ayrılan/donan/mezun öğrenci → mesaj yok (StudentActivityGate; tahsilat makbuzu hariç).
 *  - Aynı yoklama satırı için tek mesaj (AttendanceMessageGuard).
 *  - Her tetiklenme, kural olmasa bile saatlik sayaca yazılır (TriggerStats → Otomasyonlar ekranı).
 */
class AutomationEngine
{
    /**
     * @param  array<string,mixed>  $vars  Şablon değişkenleri (ogrenci_adi otomatik eklenir)
     * @param  array<string,mixed>  $context  Koşul/alıcı/dedupe bağlamı: program_id, class_group_id,
     *                                        days_offset, at_time, teacher_id, dedupe_suffix, report_card_path,
     *                                        attendance_id, missed_count …
     */
    public static function fire(string $trigger, Student $student, array $vars = [], array $context = []): void
    {
        if (config('kurs.silent_events')) {
            return; // demo seed / test verisi: dinleyiciler sessiz kalır
        }

        $branchId = $context['branch_id'] ?? $student->branch_id;
        if (! $branchId) {
            return;
        }

        TriggerStats::record((int) $branchId, $trigger);

        if (! StudentActivityGate::allows($trigger, $student->status)) {
            return; // ayrılan/donan/mezun öğrenciye otomasyon mesajı gitmez
        }

        app(BranchContext::class)->run($branchId, function () use ($trigger, $student, $vars, $context) {
            $rules = AutomationRule::query()->where('trigger', $trigger)->where('is_active', true)->get();
            if ($rules->isEmpty()) {
                return;
            }

            if (! empty($context['attendance_id']) && in_array($trigger, AttendanceMessageGuard::TRIGGERS, true)
                && ! AttendanceMessageGuard::shouldSend($trigger, AttendanceMessageGuard::doneTriggersFor($student->id, (int) $context['attendance_id']))) {
                return; // bu ders için veliye zaten bir yoklama mesajı gitti
            }

            foreach ($rules as $rule) {
                if (! ConditionEvaluator::matches($rule->conditions, $context)) {
                    continue;
                }

                self::fireRule($rule, $student, $vars, $context);
            }
        });
    }

    private static function fireRule(AutomationRule $rule, Student $student, array $vars, array $context): void
    {
        $runAt = $rule->delay_minutes > 0 ? now()->addMinutes($rule->delay_minutes) : now();
        $dedupeKey = DedupeKey::build(['rule', $rule->id, $rule->trigger, $student->id, $context['dedupe_suffix'] ?? ''], 160);

        $run = self::reserve($rule, $student, $dedupeKey, $runAt);
        if (! $run) {
            return; // aynı olay için zaten planlanmış/çalışmış
        }

        if ($rule->delay_minutes > 0) {
            DB::afterCommit(fn () => RunAutomationAction::dispatch($run->id, $rule->branch_id, $vars, $context)->delay($runAt));

            return;
        }

        self::execute($run, $rule, $student, $vars, $context);
    }

    private static function reserve(AutomationRule $rule, Student $student, string $dedupeKey, \DateTimeInterface $runAt): ?AutomationRun
    {
        try {
            return AutomationRun::query()->create([
                'automation_rule_id' => $rule->id,
                'subject_type' => $student->getMorphClass(),
                'subject_id' => $student->id,
                'status' => 'scheduled',
                'run_at' => $runAt,
                'dedupe_key' => $dedupeKey,
            ]);
        } catch (QueryException $e) {
            return null;
        }
    }

    public static function execute(AutomationRun $run, AutomationRule $rule, Student $student, array $vars, array $context): void
    {
        try {
            app(ActionExecutor::class)->run($rule, $student, $vars, $context);
            $run->forceFill(['status' => 'done', 'result' => 'Eylemler kuyruğa alındı.'])->save();
            $rule->increment('run_count');
            $rule->forceFill(['last_run_at' => now()])->save();
        } catch (\Throwable $e) {
            $run->forceFill(['status' => 'failed', 'result' => mb_substr($e->getMessage(), 0, 1000)])->save();
            Log::error('Otomasyon kuralı çalıştırılamadı', ['rule_id' => $rule->id, 'error' => $e->getMessage()]);
        }
    }
}
