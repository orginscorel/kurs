<?php

namespace App\Services\Attendance;

use App\Events\StudentNoShowToday;
use App\Models\ActivityFeed;
use App\Support\Attendance\NoShowRule;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Bugün kuruma gelmedi" tespiti: ilk dersinden X dakika sonra hâlâ girişi
 * (daily_presences.first_entry_at) olmayan öğrenciler. Ders bazlı devamsızlığın
 * (AutoAttendanceService) aksine bu BİNA girişini kontrol eder ve öğrenci başına
 * günde yalnız BİR kez activity_feed + StudentNoShowToday üretir.
 * Giriş verisi akmıyorsa (DeviceDataMonitor) hiçbir öğrenci işaretlenmez; yöneticiye günde bir uyarı gider.
 */
class NoShowDetectionService
{
    public function __construct(private readonly DeviceDataMonitor $monitor) {}

    /** @return int tespit edilen öğrenci sayısı */
    public function run(int $branchId, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $day = $now->toDateString();
        $delay = (int) Settings::get('attendance.notify_guardian_absent_delay_minutes', 15, $branchId);

        $expected = DB::table('lesson_sessions as ls')
            ->join('class_group_student as cgs', fn ($j) => $j->on('cgs.class_group_id', '=', 'ls.class_group_id')->whereNull('cgs.left_on'))
            ->where('ls.branch_id', $branchId)->where('ls.date', $day)->where('ls.status', '!=', 'cancelled')
            ->groupBy('cgs.student_id')->selectRaw('cgs.student_id, MIN(ls.starts_at) AS first_start')
            ->get()->keyBy('student_id');

        if ($expected->isEmpty()) {
            return 0;
        }

        $arrived = DB::table('daily_presences')->where('branch_id', $branchId)->where('date', $day)
            ->whereNotNull('first_entry_at')->pluck('student_id')->flip();

        $alreadyFlagged = DB::table('activity_feed')->where('branch_id', $branchId)->where('kind', 'no_show_today')
            ->whereDate('occurred_at', $day)->pluck('student_id')->flip();

        $students = DB::table('students')->whereIn('id', $expected->keys())->pluck('full_name', 'id');
        $detected = 0;
        $settings = null;
        $pause = null;
        $held = 0;

        foreach ($expected as $studentId => $row) {
            if ($arrived->has($studentId) || $alreadyFlagged->has($studentId)) {
                continue;
            }
            if (! NoShowRule::shouldFlag(CarbonImmutable::parse($row->first_start), $delay, $now)) {
                continue;
            }
            if ($pause === null) {
                $settings = Settings::group('attendance', $branchId);
                $pause = $this->monitor->pauseReason($branchId, $now, $settings) ?? false;
            }
            if ($pause !== false) {
                $held++;

                continue; // giriş verisi yok: "gelmedi" sayma
            }

            ActivityFeed::query()->create([
                'branch_id' => $branchId,
                'kind' => 'no_show_today',
                'message' => ($students[$studentId] ?? 'Öğrenci').' bugün kuruma henüz giriş yapmadı.',
                'student_id' => $studentId,
                'subject_type' => 'student',
                'subject_id' => $studentId,
                'occurred_at' => $now,
            ]);

            DB::afterCommit(fn () => event(new StudentNoShowToday($studentId, $day)));
            $detected++;
        }

        if ($pause !== null && $pause !== false && $held > 0) {
            $this->monitor->warn($branchId, $pause, $now, (int) ($settings['device_stale_minutes'] ?? 30), $held);
        }

        return $detected;
    }
}
