<?php

namespace App\Services\Attendance;

use App\Events\StudentMarkedAbsent;
use App\Events\StudentMarkedLate;
use App\Models\ActivityFeed;
use App\Models\Attendance;
use App\Models\DailyPresence;
use App\Models\LessonSession;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Biyometrik girişlerden ders yoklaması üretir (her 5 dakikada zamanlayıcı çalıştırır).
 *
 * Ders 09:00, geç eşiği 15 dk, yok eşiği 30 dk ise:
 *  - 09:15'e kadar giriş → VAR
 *  - 09:15–09:30 arası giriş → GEÇ (geç dakika kaydedilir)
 *  - 09:30'da hâlâ giriş yok → GELMEDİ
 * Öğretmen/yönetici tarafından elle girilmiş yoklama ASLA ezilmez.
 *
 * Giriş verisi akmıyorsa (cihazlar X dk sessiz / bugün hiç giriş yok — DeviceDataMonitor) GELMEDİ yazılmaz;
 * bekletilen öğrenci-ders sayısı `held` olarak döner ve yöneticilere günde bir uyarı gider.
 */
class AutoAttendanceService
{
    public function __construct(
        private readonly DeviceDataMonitor $monitor,
        private readonly \App\Services\Discipline\SuspensionCalendar $suspensions = new \App\Services\Discipline\SuspensionCalendar(),
    ) {}

    /** @return array{sessions:int, present:int, late:int, absent:int, held:int, paused:?string} */
    public function run(int $branchId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $settings = Settings::group('attendance', $branchId);
        $stats = ['sessions' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'held' => 0, 'paused' => null];
        $pause = null; // null = henüz bakılmadı (yalnız GELMEDİ yazılacaksa sorgulanır); false = veri akıyor

        if (! ($settings['auto_absence_enabled'] ?? true)) {
            return $stats;
        }

        $lateAfter = (int) $settings['late_after_minutes'];
        $absentAfter = (int) $settings['absent_after_minutes'];

        $sessions = LessonSession::query()
            ->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)
            ->whereDate('date', $now->toDateString())
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<=', $now)
            ->with(['classGroup.activeStudents:id,full_name', 'subject:id,name'])
            ->get();

        // Disiplin: bugün uzaklaştırmada olan öğrenciler "gelmedi" yerine ayardaki durumla ve notla işlenir, veli alarmı üretilmez
        $suspended = $this->suspensions->onDate(
            $sessions->flatMap(fn ($s) => $s->classGroup?->activeStudents->pluck('id') ?? collect()),
            $now->toDateString(),
        );
        $suspendedStatus = $suspended ? \App\Services\Discipline\DisciplineSettings::get('suspension_attendance_status', $branchId) : null;

        foreach ($sessions as $session) {
            $stats['sessions']++;
            $start = CarbonImmutable::parse($session->starts_at);
            $lateThreshold = $start->addMinutes($lateAfter);
            $absentThreshold = $start->addMinutes($absentAfter);
            $studentIds = $session->classGroup?->activeStudents->pluck('id') ?? collect();

            if ($studentIds->isEmpty()) {
                continue;
            }

            $existing = Attendance::query()->withoutGlobalScope('branch')
                ->where('lesson_session_id', $session->id)->pluck('method', 'student_id');

            $presences = DailyPresence::query()->withoutGlobalScope('branch')
                ->whereIn('student_id', $studentIds)->whereDate('date', $now->toDateString())
                ->get()->keyBy('student_id');

            foreach ($session->classGroup->activeStudents as $student) {
                $currentMethod = $existing[$student->id] ?? null;

                // Elle girilmiş yoklamaya dokunma.
                if ($currentMethod !== null && $currentMethod !== 'auto') {
                    continue;
                }

                $entry = $presences[$student->id]->first_entry_at ?? null;
                $entry = $entry ? CarbonImmutable::parse($entry) : null;

                if ($entry && $entry->lte($lateThreshold)) {
                    $status = 'present';
                    $lateMinutes = null;
                } elseif ($entry && $entry->lte($session->ends_at)) {
                    $status = 'late';
                    $lateMinutes = (int) $start->diffInMinutes($entry);
                } elseif ($now->gte($absentThreshold) && isset($suspended[$student->id])) {
                    $status = $suspendedStatus === 'absent' ? 'absent' : 'excused'; // uzaklaştırma: cihaz verisi beklenmez
                    $lateMinutes = null;
                } elseif ($now->gte($absentThreshold)) {
                    $pause ??= $this->monitor->pauseReason($branchId, $now, $settings) ?? false;
                    if ($pause !== false) {
                        $stats['held']++;

                        continue; // giriş verisi yok: GELMEDİ yazma, veriyi bekle
                    }
                    $status = 'absent';
                    $lateMinutes = null;
                } else {
                    continue; // eşik dolmadı, beklemeye devam
                }

                $previous = $currentMethod !== null
                    ? Attendance::query()->withoutGlobalScope('branch')->where('lesson_session_id', $session->id)->where('student_id', $student->id)->value('status')
                    : null;

                if ($previous === $status) {
                    continue;
                }

                $disciplineNote = ($status !== 'present' && $status !== 'late') ? ($suspended[$student->id]['label'] ?? null) : null;

                DB::transaction(function () use ($session, $student, $status, $lateMinutes, $branchId, $previous, $disciplineNote) {
                    $attendance = Attendance::query()->withoutGlobalScope('branch')->updateOrCreate(
                        ['lesson_session_id' => $session->id, 'student_id' => $student->id],
                        [
                            'branch_id' => $branchId,
                            'date' => $session->date,
                            'status' => $status,
                            'late_minutes' => $lateMinutes,
                            'method' => 'auto',
                        ] + ($disciplineNote ? ['note' => $disciplineNote] : []),
                    );

                    if ($disciplineNote === null && in_array($status, ['absent', 'late'], true) && $previous !== $status) {
                        ActivityFeed::query()->withoutGlobalScope('branch')->create([
                            'branch_id' => $branchId,
                            'kind' => $status,
                            'message' => sprintf('%s %s dersine %s', $student->full_name, $session->subject->name, $status === 'absent' ? 'gelmedi' : "geç kaldı ({$lateMinutes} dk)"),
                            'student_id' => $student->id,
                            'subject_type' => 'lesson_session',
                            'subject_id' => $session->id,
                            'occurred_at' => now(),
                        ]);

                        DB::afterCommit(fn () => event($status === 'late' ? new StudentMarkedLate($attendance->id) : new StudentMarkedAbsent($attendance->id)));
                    }
                });

                $stats[$status] = ($stats[$status] ?? 0) + 1;
            }
        }

        if ($pause !== null && $pause !== false) {
            $stats['paused'] = $pause;
            if ($stats['held'] > 0) {
                $this->monitor->warn($branchId, $pause, $now, (int) ($settings['device_stale_minutes'] ?? 30), $stats['held']);
            }
        }

        return $stats;
    }
}
