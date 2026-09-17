<?php

namespace App\Sync\Local;

use App\Models\Enrollment;
use App\Services\Finance\InstallmentPlanService;
use Illuminate\Support\Facades\DB;

/**
 * Yerel düğümde ödeme planı yeniden yapılandırma ve indirim/burs değişikliği komut olarak eşitlenir.
 * Plan toplu sorgularla da değiştiği (silme, sıra numarası) için kaydın tüm taksitleri "dokunulan" sayılır:
 * sunucu reddederse (ör. taksit çevrimdışıyken ödendi) yerel plan sunucudakine geri döner.
 */
class LocalInstallmentPlanService extends InstallmentPlanService
{
    public function restructure(Enrollment $enrollment, array $rows, ?string $note = null): Enrollment
    {
        return app(LocalCommandRecorder::class)->run('installment_plan.restructure', [
            'enrollment' => $enrollment->id,
            'rows' => array_values(array_map(fn ($r) => ['id' => $r['id'] ?? null, 'due_date' => $r['due_date'], 'amount' => (string) $r['amount']], $rows)),
            'note' => $note,
        ], function () use ($enrollment, $rows, $note) {
            $this->touchPlan($enrollment);

            return parent::restructure($enrollment, $rows, $note);
        });
    }

    public function adjustPrice(Enrollment $enrollment, array $data): Enrollment
    {
        return app(LocalCommandRecorder::class)->run('installment_plan.adjust_price', [
            'enrollment' => $enrollment->id,
            'data' => array_intersect_key($data, array_flip(['discount_amount', 'discount_reason', 'scholarship_amount', 'scholarship_reason'])),
        ], function () use ($enrollment, $data) {
            $this->touchPlan($enrollment);

            return parent::adjustPrice($enrollment, $data);
        });
    }

    private function touchPlan(Enrollment $enrollment): void
    {
        $recorder = app(LocalCommandRecorder::class);
        $recorder->touch('enrollments', [$enrollment->id]);
        $recorder->touch('installments', DB::table('installments')->where('enrollment_id', $enrollment->id)->pluck('id'));
    }
}
