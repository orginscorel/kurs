<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Notifications\DailyDigest;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\StaffRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Yönetici günlük özeti (uygulama bildirimi, `dashboard.view` yetkililer):
 *  morning (08:00): bugün beklenen öğrenci, dünkü devamsızlık, bugün vadeli tahsilat, yüksek riskli sayısı
 *  evening (20:00): bugün gelmeyenler, ders devamsızlığı, günün tahsilat özeti
 * Gün + tür başına kullanıcı başına tek bildirim (data.once).
 */
class SendDailyDigest extends Command
{
    protected $signature = 'kurs:daily-digest {kind : morning|evening} {--date= : YYYY-MM-DD (test)} {--user= : Yalnız bu kullanıcıya (test)}';

    protected $description = 'Yöneticilere sabah/akşam özet bildirimi gönderir';

    public function handle(NotificationService $notifications): int
    {
        $kind = $this->argument('kind');
        if (! in_array($kind, ['morning', 'evening'], true)) {
            $this->error('kind morning ya da evening olmalı.');

            return self::FAILURE;
        }

        $day = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();

        foreach (Branch::query()->where('is_active', true)->get() as $branch) {
            $stats = $kind === 'morning' ? self::morningStats($branch->id, $day) : self::eveningStats($branch->id, $day);
            $digest = $kind === 'morning' ? DailyDigest::morning($stats) : DailyDigest::evening($stats);

            $users = StaffRecipients::withPermission($branch->id, 'dashboard.view');
            if ($this->option('user')) {
                $users = array_values(array_intersect($users, [(int) $this->option('user')]));
            }
            $n = $notifications->notify($users, $digest['type'], $digest['title'], $digest['body'], '/',
                ['kind' => "digest_{$kind}", 'date' => $day->toDateString(), 'once' => "digest:{$kind}:{$branch->id}:".$day->toDateString()]);

            $this->line("{$branch->name}: {$n} kullanıcıya {$kind} özeti.");
        }

        return self::SUCCESS;
    }

    public static function expectedStudentIds(int $branchId, string $date)
    {
        return DB::table('lesson_sessions as ls')
            ->join('class_group_student as cgs', fn ($j) => $j->on('cgs.class_group_id', '=', 'ls.class_group_id')->whereNull('cgs.left_on'))
            ->join('students as st', fn ($j) => $j->on('st.id', '=', 'cgs.student_id')->whereNull('st.deleted_at'))
            ->where('ls.branch_id', $branchId)->where('ls.date', $date)->where('ls.status', '!=', 'cancelled')
            ->distinct()->pluck('cgs.student_id');
    }

    public static function morningStats(int $branchId, CarbonImmutable $day): array
    {
        $yesterday = $day->subDay()->toDateString();
        $absent = DB::table('attendances')->where('branch_id', $branchId)->where('date', $yesterday)->where('status', 'absent')
            ->selectRaw('COUNT(DISTINCT student_id) AS students, COUNT(*) AS lessons')->first();
        $due = DB::table('installments')->where('branch_id', $branchId)->where('due_date', $day->toDateString())
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(amount - paid_amount), 0) AS remaining')->first();

        return [
            'date_label' => $day->format('d.m.Y'),
            'expected_today' => self::expectedStudentIds($branchId, $day->toDateString())->count(),
            'absent_yesterday' => (int) $absent->students,
            'absent_yesterday_lessons' => (int) $absent->lessons,
            'due_today_count' => (int) $due->c,
            'due_today_amount' => (string) $due->remaining,
            'overdue_count' => DB::table('installments')->where('branch_id', $branchId)->where('status', 'overdue')->count(),
            'high_risk' => DB::table('student_risk_scores as r')->join('students as s', 's.id', '=', 'r.student_id')
                ->where('s.branch_id', $branchId)->whereNull('s.deleted_at')->whereIn('s.status', ['active', 'enrolled'])->where('r.level', 'high')->count(),
        ];
    }

    public static function eveningStats(int $branchId, CarbonImmutable $day): array
    {
        $date = $day->toDateString();
        $expected = self::expectedStudentIds($branchId, $date);
        $arrived = DB::table('daily_presences')->where('branch_id', $branchId)->where('date', $date)->whereNotNull('first_entry_at')->pluck('student_id')->flip();
        $noShowIds = $expected->reject(fn ($id) => $arrived->has($id))->values();
        $collected = DB::table('payments')->where('branch_id', $branchId)->whereNull('voided_at')
            ->whereBetween('paid_at', [$day->startOfDay(), $day->endOfDay()])
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(amount), 0) AS total')->first();

        return [
            'date_label' => $day->format('d.m.Y'),
            'expected_today' => $expected->count(),
            'arrived_today' => $expected->filter(fn ($id) => $arrived->has($id))->count(),
            'no_show_names' => DB::table('students')->whereIn('id', $noShowIds->take(5))->orderBy('full_name')->pluck('full_name')->all(),
            'absent_today' => DB::table('attendances')->where('branch_id', $branchId)->where('date', $date)->where('status', 'absent')->distinct()->count('student_id'),
            'collected_count' => (int) $collected->c,
            'collected_amount' => (string) $collected->total,
            'voided_count' => DB::table('payments')->where('branch_id', $branchId)->whereBetween('voided_at', [$day->startOfDay(), $day->endOfDay()])->count(),
        ];
    }
}
