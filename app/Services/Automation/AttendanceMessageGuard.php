<?php

namespace App\Services\Automation;

use App\Models\AutomationRun;

/**
 * "Aynı öğrenci, aynı ders → tek mesaj" kuralı. Bir yoklama satırı gün içinde GELMEDİ → GEÇ
 * (ya da öğretmen düzeltmesiyle tersi) değişebilir; veliye bu ders için yalnız BİR yoklama
 * mesajı gider. Gecikmeli "gelmedi" kuralı henüz çalışmadıysa (scheduled) zaten Revalidator
 * tarafından atlanır; burada yalnız fiilen çalışmış (done) kayıtlar engeller.
 */
class AttendanceMessageGuard
{
    public const TRIGGERS = ['attendance.absent', 'attendance.late'];

    /** Yoklama satırına özgü dedupe son eki (her iki tetikleyici aynı öneki paylaşır). */
    public static function suffix(int $attendanceId, string $trigger): string
    {
        return "att:{$attendanceId}:".($trigger === 'attendance.late' ? 'late' : 'absent');
    }

    /**
     * @param  list<string>  $doneTriggers  bu yoklama satırı için daha önce ÇALIŞMIŞ (done) tetikleyiciler
     */
    public static function shouldSend(string $trigger, array $doneTriggers): bool
    {
        if (! in_array($trigger, self::TRIGGERS, true)) {
            return true;
        }

        // Aynı tetikleyicinin tekrarını dedupe anahtarı zaten engeller; burada DİĞER yoklama tetikleyicisine bakılır.
        return array_diff(array_intersect($doneTriggers, self::TRIGGERS), [$trigger]) === [];
    }

    /** @return list<string> bu yoklama satırı için çalışmış (done) yoklama tetikleyicileri */
    public static function doneTriggersFor(int $studentId, int $attendanceId): array
    {
        return AutomationRun::query()
            ->where('subject_type', 'student')->where('subject_id', $studentId)->where('status', 'done')
            ->where('dedupe_key', 'like', "%:att:{$attendanceId}:%")
            ->pluck('dedupe_key')
            ->map(fn (string $k) => self::triggerFromDedupeKey($k))->filter()->unique()->values()->all();
    }

    /** automation_runs.dedupe_key içinden tetikleyiciyi çıkarır ("rule:ID:attendance.late:STUDENT:att:…"). */
    public static function triggerFromDedupeKey(string $key): ?string
    {
        foreach (self::TRIGGERS as $t) {
            if (str_contains($key, ":{$t}:")) {
                return $t;
            }
        }

        return null;
    }
}
