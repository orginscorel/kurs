<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Homework;
use App\Models\Student;
use App\Services\Automation\AutomationEngine;
use App\Services\Notifications\NotificationService;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\BranchContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ödev kapanışının (kurs:close-homework, saatlik) ardından:
 *  - YAPILMADI olan her öğrenci için trigger `homework.missed` (öğrenci başına tek sefer; context
 *    `missed_count` = son 30 günde yapılmayan ödev sayısı → "tekrarlıyorsa veliye" kuralı `min_missed_count` ile),
 *  - ödevi veren öğretmene "kontrol edilecek ödev" uygulama bildirimi (ödev başına tek sefer),
 *  - webhook `homework.missed` (ödev başına özet).
 * HomeworkService'e dokunmaz; kapanmış teslim satırlarını okur.
 */
class NotifyMissedHomework extends Command
{
    protected $signature = 'kurs:homework-missed-notify {--hours=72 : Son teslimi bu kadar saat içinde geçmiş ödevler} {--homework= : Yalnız bu ödev (test)}';

    protected $description = 'Teslim edilmeyen ödevler için öğrenci/veli otomasyonu ve öğretmene kontrol bildirimi üretir';

    public function handle(NotificationService $notifications, WebhookDispatcher $webhooks): int
    {
        $total = 0;
        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            $total += app(BranchContext::class)->run($branch->id, fn () => $this->runForBranch($branch->id, $notifications, $webhooks));
        }
        if ($total > 0) {
            $this->line("{$total} ödev işlendi.");
        }

        return self::SUCCESS;
    }

    private function runForBranch(int $branchId, NotificationService $notifications, WebhookDispatcher $webhooks): int
    {
        $homeworks = Homework::query()->with(['subject:id,name', 'teacher:id,user_id,first_name,last_name'])
            ->where('due_at', '<', now())->where('due_at', '>=', now()->subHours((int) $this->option('hours')))
            ->when($this->option('homework'), fn ($q, $id) => $q->whereKey((int) $id))->get();

        $count = 0;
        foreach ($homeworks as $hw) {
            $stats = DB::table('homework_submissions')->where('homework_id', $hw->id)
                ->selectRaw("COUNT(*) AS total, SUM(status IN ('submitted','late')) AS done, SUM(status = 'missed') AS missed, SUM(status IN ('assigned','seen')) AS open_count")
                ->first();

            if ((int) $stats->open_count > 0) {
                continue; // kapanış henüz çalışmamış; bir sonraki turda
            }
            if (! cache()->add("homework_missed_processed:{$hw->id}", 1, now()->addDays(5))) {
                continue; // bu ödev zaten işlendi (tetikleyici sayacı ve webhook tekrar etmesin)
            }

            $missedIds = DB::table('homework_submissions')->where('homework_id', $hw->id)->where('status', 'missed')->pluck('student_id')->all();
            foreach (Student::query()->whereIn('id', $missedIds)->get() as $student) {
                $missedCount = (int) DB::table('homework_submissions as hs')->join('homework as h', 'h.id', '=', 'hs.homework_id')
                    ->where('hs.student_id', $student->id)->where('hs.status', 'missed')->whereNull('h.deleted_at')
                    ->where('h.due_at', '>=', now()->subDays(30))->count();

                AutomationEngine::fire('homework.missed', $student, [
                    'odev_adi' => $hw->title,
                    'ders_adi' => $hw->subject?->name ?? '',
                    'teslim_tarihi' => $hw->due_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? '',
                    'tekrar_sayisi' => (string) $missedCount,
                ], [
                    'class_group_id' => $hw->class_group_id,
                    'missed_count' => $missedCount,
                    'dedupe_suffix' => "homework_missed:{$hw->id}",
                ]);
            }

            $teacherUserId = $hw->teacher?->user_id;
            if ($teacherUserId && (int) $stats->total > 0) {
                $notifications->notify((int) $teacherUserId, 'academic', "Kontrol edilecek ödev: {$hw->title}",
                    sprintf('%s · %d teslim, %d yapılmadı (toplam %d). Değerlendirmeyi tamamlayın.', $hw->subject?->name ?? 'Ödev', (int) $stats->done, (int) $stats->missed, (int) $stats->total),
                    "/odevler/{$hw->id}",
                    ['kind' => 'homework_review', 'homework_id' => $hw->id, 'once' => "homework_review:{$hw->id}"]);
            }

            if ($missedIds !== []) {
                $webhooks->dispatch('homework.missed', [
                    'homework_id' => $hw->id, 'class_group_id' => $hw->class_group_id, 'due_at' => $hw->due_at?->toIso8601String(),
                    'total' => (int) $stats->total, 'done' => (int) $stats->done, 'missed' => (int) $stats->missed, 'missed_student_ids' => array_map('intval', $missedIds),
                ], $branchId);
            }
            $count++;
        }

        return $count;
    }
}
