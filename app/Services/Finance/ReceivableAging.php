<?php

namespace App\Services\Finance;

use Carbon\CarbonImmutable;

/**
 * Alacak yaşlandırma. Vadesi gelmemiş alacak "current"; vadesi geçenler gecikme gününe göre kovalara ayrılır.
 * Kova sınırları tek yerde; hem rapor hem liste bu sınıfı kullanır.
 */
final class ReceivableAging
{
    public const BUCKETS = [
        'current' => 'Vadesi gelmemiş',
        'd0_30' => '0-30 gün',
        'd31_60' => '31-60 gün',
        'd61_90' => '61-90 gün',
        'd90_plus' => '90+ gün',
    ];

    /** Vadeye göre gecikme günü: vade bugün ya da ileride ise 0 veya negatif. */
    public static function daysOverdue(string|\DateTimeInterface $dueDate, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $due = $dueDate instanceof \DateTimeInterface ? CarbonImmutable::instance($dueDate)->startOfDay() : CarbonImmutable::parse($dueDate)->startOfDay();

        return (int) $due->diffInDays($today->startOfDay(), false);
    }

    public static function bucketFor(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => 'd0_30',
            $daysOverdue <= 60 => 'd31_60',
            $daysOverdue <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }

    /**
     * @param iterable<array{due_date: string|\DateTimeInterface, remaining: string}|object> $rows
     * @return array{buckets: array<string, array{key: string, label: string, amount: string, count: int}>, total: string, overdue: string}
     */
    public static function summarize(iterable $rows, ?CarbonImmutable $today = null): array
    {
        $buckets = [];
        foreach (self::BUCKETS as $key => $label) {
            $buckets[$key] = ['key' => $key, 'label' => $label, 'amount' => '0.00', 'count' => 0];
        }
        $total = '0.00';

        foreach ($rows as $row) {
            $row = (array) $row;
            $remaining = bcadd((string) $row['remaining'], '0', 2);
            if (bccomp($remaining, '0', 2) <= 0) {
                continue;
            }
            $key = self::bucketFor(self::daysOverdue($row['due_date'], $today));
            $buckets[$key]['amount'] = bcadd($buckets[$key]['amount'], $remaining, 2);
            $buckets[$key]['count']++;
            $total = bcadd($total, $remaining, 2);
        }

        return [
            'buckets' => $buckets,
            'total' => $total,
            'overdue' => bcsub($total, $buckets['current']['amount'], 2),
        ];
    }

    /** Kova koşulu SQL'i (bucketFor ile aynı sınırlar). $today güvenli Y-m-d olmalıdır. */
    public static function sqlCondition(string $bucket, string $dueColumn, string $today): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) || ! preg_match('/^[a-z_.]+$/', $dueColumn)) {
            throw new \InvalidArgumentException('Geçersiz yaşlandırma parametresi.');
        }
        $days = "DATEDIFF('{$today}', {$dueColumn})";

        return match ($bucket) {
            'current' => "{$days} <= 0",
            'd0_30' => "{$days} BETWEEN 1 AND 30",
            'd31_60' => "{$days} BETWEEN 31 AND 60",
            'd61_90' => "{$days} BETWEEN 61 AND 90",
            'd90_plus' => "{$days} > 90",
            default => throw new \InvalidArgumentException('Geçersiz yaşlandırma kovası.'),
        };
    }

    public static function sqlSum(string $bucket, string $dueColumn, string $amountExpr, string $today): string
    {
        return 'COALESCE(SUM(CASE WHEN '.self::sqlCondition($bucket, $dueColumn, $today)." THEN {$amountExpr} ELSE 0 END), 0)";
    }
}
