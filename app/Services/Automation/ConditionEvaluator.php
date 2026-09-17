<?php

namespace App\Services\Automation;

/**
 * Kural koşullarını olay bağlamıyla karşılaştırır. Bilinmeyen koşul anahtarları yok sayılır
 * (ileride eklenecek koşul türleri eski kuralları bozmaz).
 */
class ConditionEvaluator
{
    public static function matches(?array $conditions, array $context): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $key => $value) {
            if (! self::check($key, $value, $context)) {
                return false;
            }
        }

        return true;
    }

    private static function check(string $key, mixed $value, array $context): bool
    {
        return match ($key) {
            'program_ids' => ! array_key_exists('program_id', $context) || in_array($context['program_id'], (array) $value, false),
            'class_group_ids' => ! array_key_exists('class_group_id', $context) || in_array($context['class_group_id'], (array) $value, false),
            'days' => ! array_key_exists('days_offset', $context) || (int) $context['days_offset'] === (int) $value,
            'time' => ! array_key_exists('at_time', $context) || $context['at_time'] === $value,
            'minutes_before' => ! array_key_exists('minutes_before', $context) || (int) $context['minutes_before'] === (int) $value,
            // Tekrar eden durum: ör. son 30 günde en az N ödev yapılmadıysa veliye de bildir.
            'min_missed_count' => ! array_key_exists('missed_count', $context) || (int) $context['missed_count'] >= (int) $value,
            default => true,
        };
    }
}
