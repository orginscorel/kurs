<?php

namespace App\Listeners\Automation;

use App\Events\StudentClassChanged;
use App\Models\ActivityFeed;
use App\Models\Student;
use App\Services\Automation\AutomationEngine;
use App\Services\Webhooks\WebhookDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Öğrencinin sınıfı değişti →
 *  - canlı akış satırı (tekil değişim/takas/geri alma; toplu yerleştirme/seviye atlatma akışı doldurmasın diye yazılmaz),
 *  - webhook `student.class_changed` (demo dönüşümü hariç),
 *  - otomasyon tetikleyicisi `student.class_changed` (veliye bilgi): yalnız change / swap / promotion ve
 *    öğrenci YENİ bir sınıfa girdiğinde. Toplu otomatik yerleştirme (placement) ve restructure tetiklemez.
 */
class OnStudentClassChanged
{
    /** Veliye bildirim üretebilecek kaynaklar. */
    public const NOTIFY_SOURCES = ['change', 'swap', 'promotion'];

    /** Canlı akışa tek tek yazılan kaynaklar. */
    public const FEED_SOURCES = ['change', 'swap', 'revert'];

    public static function shouldTrigger(string $source, ?int $toClassGroupId): bool
    {
        return $toClassGroupId !== null && in_array($source, self::NOTIFY_SOURCES, true);
    }

    public function handle(StudentClassChanged $event): void
    {
        if (config('kurs.silent_events')) {
            return;
        }

        $student = Student::query()->withoutGlobalScope('branch')->find($event->studentId);
        if (! $student) {
            return;
        }

        $groups = DB::table('class_groups')->whereIn('id', array_filter([$event->fromClassGroupId, $event->toClassGroupId]))
            ->get(['id', 'name', 'program_id'])->keyBy('id');
        $fromName = $event->fromClassGroupId ? ($groups[$event->fromClassGroupId]->name ?? null) : null;
        $toName = $event->toClassGroupId ? ($groups[$event->toClassGroupId]->name ?? null) : null;

        // Seviye atlatmada yeni sınıfa giriş olayı "sınıfsızdan" gelir; eski sınıf son kapanan üyeliktir.
        if ($fromName === null && $event->toClassGroupId !== null && $event->source === 'promotion') {
            $fromName = DB::table('class_group_student as cgs')->join('class_groups as cg', 'cg.id', '=', 'cgs.class_group_id')
                ->where('cgs.student_id', $student->id)->where('cgs.class_group_id', '!=', $event->toClassGroupId)->whereNotNull('cgs.left_on')
                ->orderByDesc('cgs.left_on')->orderByDesc('cgs.id')->value('cg.name');
        }

        $date = CarbonImmutable::parse($event->effectiveOn);

        if (in_array($event->source, self::FEED_SOURCES, true)) {
            $verb = match (true) {
                $toName === null => ($fromName ? "{$fromName} sınıfından çıkarıldı" : 'sınıfsız kaldı'),
                $fromName === null => "{$toName} sınıfına yerleştirildi",
                default => "sınıfı {$fromName} → {$toName} olarak değişti",
            };
            ActivityFeed::query()->withoutGlobalScope('branch')->create([
                'branch_id' => $student->branch_id, 'kind' => 'class_change',
                'message' => mb_substr("{$student->full_name} {$verb}".($event->source === 'swap' ? ' (takas)' : ($event->source === 'revert' ? ' (geri alma)' : '')), 0, 300),
                'student_id' => $student->id, 'subject_type' => 'student', 'subject_id' => $student->id,
                'meta' => ['from' => $event->fromClassGroupId, 'to' => $event->toClassGroupId, 'source' => $event->source],
                'occurred_at' => now(),
            ]);
        }

        if ($event->source !== 'restructure') {
            app(WebhookDispatcher::class)->dispatch('student.class_changed', [
                'student_id' => $student->id, 'student_no' => $student->student_no,
                'from_class_group_id' => $event->fromClassGroupId, 'from_class_group' => $fromName,
                'to_class_group_id' => $event->toClassGroupId, 'to_class_group' => $toName,
                'source' => $event->source, 'effective_on' => $date->toDateString(),
            ], (int) $student->branch_id);
        }

        if (! self::shouldTrigger($event->source, $event->toClassGroupId)) {
            return;
        }

        AutomationEngine::fire('student.class_changed', $student, [
            'eski_sinif' => $fromName ?? '—',
            'yeni_sinif' => $toName ?? '—',
            'tarih' => $date->format('d.m.Y'),
            'aciklama' => (string) ($event->reason ?? ''),
        ], [
            'class_group_id' => $event->toClassGroupId,
            'program_id' => $groups[$event->toClassGroupId]->program_id ?? null,
            'dedupe_suffix' => "class_changed:{$event->toClassGroupId}:{$date->toDateString()}",
        ]);
    }
}
