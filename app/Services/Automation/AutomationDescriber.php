<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;

/**
 * Kural için okunur Türkçe cümle üretir (form önizlemesi): "Taksit 3 gün gecikince velisine WhatsApp gönder."
 */
class AutomationDescriber
{
    private const TO_LABELS = ['student' => 'öğrenciye', 'guardian' => 'velisine', 'teacher' => 'öğretmenine', 'admin' => 'yöneticilere'];

    private const TYPE_LABELS = ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'e-posta', 'app' => 'uygulama bildirimi'];

    public static function describe(string $trigger, ?array $conditions, array $actions, int $delayMinutes = 0): string
    {
        $when = self::whenClause($trigger, $conditions);
        $delay = $delayMinutes > 0 ? " {$delayMinutes} dakika sonra" : '';
        $actionsText = self::actionsClause($actions);

        return trim("{$when}{$delay} {$actionsText}.");
    }

    private static function whenClause(string $trigger, ?array $conditions): string
    {
        $days = $conditions['days'] ?? null;

        return match ($trigger) {
            'attendance.absent' => 'Öğrenci derse gelmediğinde',
            'attendance.late' => 'Öğrenci derse geç kaldığında',
            'student.entry' => 'Öğrenci kuruma giriş yaptığında',
            'student.exit' => 'Öğrenci kurumdan çıktığında',
            'student.no_show_today' => 'Öğrenci bugün kuruma gelmediğinde',
            'installment.upcoming' => $days ? "Taksit vadesine {$days} gün kala" : 'Taksit vadesi yaklaştığında',
            'installment.due' => 'Taksit vadesi geldiğinde',
            'installment.overdue' => $days ? "Taksit {$days} gün gecikince" : 'Taksit gecikince',
            'payment.received' => 'Tahsilat alındığında',
            'exam.result_published' => 'Sınav sonucu yayımlandığında',
            'homework.due_tomorrow' => 'Ödev tesliminin son günü yarın olduğunda',
            'lesson.starting' => 'Ders başlamak üzereyken',
            'lesson.cancelled' => 'Ders iptal edildiğinde',
            'schedule.tomorrow' => 'Her akşam yarınki ders programı hazır olduğunda',
            'enrollment.welcome' => 'Yeni kayıt tamamlandığında',
            'homework.missed' => ! empty($conditions['min_missed_count'])
                ? "Öğrenci son 30 günde en az {$conditions['min_missed_count']} ödevi yapmadığında"
                : 'Ödev teslim edilmediğinde',
            'risk.high' => 'Öğrenci yüksek risk seviyesine geçtiğinde',
            'student.class_changed' => 'Öğrencinin sınıfı değiştiğinde',
            default => AutomationRule::TRIGGERS[$trigger] ?? $trigger,
        };
    }

    private static function actionsClause(array $actions): string
    {
        $parts = array_map(function ($a) {
            $to = self::TO_LABELS[$a['to'] ?? 'guardian'] ?? 'ilgilisine';
            $type = self::TYPE_LABELS[$a['type'] ?? 'whatsapp'] ?? ($a['type'] ?? '');

            return "{$to} {$type} gönder";
        }, $actions ?: []);

        return $parts ? implode(', ayrıca ', $parts) : 'hiçbir eylem tanımlanmadı';
    }
}
