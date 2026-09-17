<?php

namespace Tests\Unit;

use App\Services\System\BackupService;
use PHPUnit\Framework\TestCase;

/** Yedek saklama/rotasyon hesabı: DB'den bağımsız saf mantık. */
class BackupRotationTest extends TestCase
{
    public function test_keeps_newest_n_and_prunes_the_rest(): void
    {
        // id'ler en yeniden en eskiye sıralı geldiği varsayılır (finished_at DESC)
        $ordered = [50, 49, 48, 47, 46, 45, 44];

        $this->assertSame([47, 46, 45, 44], BackupService::idsToPrune($ordered, keep: 3));
    }

    public function test_nothing_pruned_when_count_within_keep_limit(): void
    {
        $this->assertSame([], BackupService::idsToPrune([3, 2, 1], keep: 14));
    }

    public function test_negative_keep_is_treated_as_zero(): void
    {
        $this->assertSame([3, 2, 1], BackupService::idsToPrune([3, 2, 1], keep: -5));
    }

    public function test_empty_list_prunes_nothing(): void
    {
        $this->assertSame([], BackupService::idsToPrune([], keep: 14));
    }

    public function test_daily_and_weekly_defaults_match_requirements(): void
    {
        // Gereksinim: günlük 14, haftalık 8 varsayılan saklama
        $this->assertSame([], BackupService::idsToPrune(range(1, 14), keep: 14));
        $this->assertCount(1, BackupService::idsToPrune(range(1, 15), keep: 14));
        $this->assertSame([], BackupService::idsToPrune(range(1, 8), keep: 8));
    }
}
