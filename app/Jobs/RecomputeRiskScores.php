<?php

namespace App\Jobs;

use App\Models\Student;
use App\Services\Students\StudentInsights;
use App\Support\BranchContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Verilen öğrencilerin risk puanını yeniden hesaplar (sınav yayımı, rehberlik görüşmesi sonrası).
 * Seviye geçişi (→ high) eylemleri StudentRiskScore model olayından (OnRiskScoreSaved) çalışır.
 */
class RecomputeRiskScores implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** @param list<int> $studentIds */
    public function __construct(public readonly array $studentIds, public readonly int $branchId, public readonly string $reason = '') {}

    public function handle(StudentInsights $insights): void
    {
        app(BranchContext::class)->run($this->branchId, function () use ($insights) {
            foreach (array_chunk(array_values(array_unique($this->studentIds)), 200) as $chunk) {
                Student::query()->whereIn('id', $chunk)->whereIn('status', ['active', 'enrolled', 'frozen'])->with('riskScore')
                    ->get()->each(fn (Student $s) => $insights->riskFor($s, refresh: true));
            }
        });
    }
}
