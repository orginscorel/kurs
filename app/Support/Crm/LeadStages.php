<?php

namespace App\Support\Crm;

use App\Exceptions\BusinessRuleException;
use App\Models\Lead;

/**
 * Aday hattı (kanban) aşama geçiş kuralları — saf mantık, DB'ye dokunmaz.
 * Kanban sürükle-bırak ve API her ikisi de aynı kuralı kullanır.
 */
final class LeadStages
{
    /**
     * @throws BusinessRuleException  geçiş kural dışıysa
     */
    public static function assertTransition(string $from, string $to, ?string $lostReason, bool $alreadyHasStudent): void
    {
        if (! array_key_exists($to, Lead::STAGES)) {
            throw new BusinessRuleException('Geçersiz aşama.', 'invalid_stage');
        }

        if ($from === $to) {
            return;
        }

        if ($alreadyHasStudent) {
            throw new BusinessRuleException('Kayda dönüşmüş bir aday başka aşamaya taşınamaz.', 'lead_already_converted');
        }

        if ($to === 'won') {
            throw new BusinessRuleException('"Kayıt Oldu" aşamasına yalnızca "Kayda dönüştür" işlemiyle geçilebilir.', 'won_requires_conversion');
        }

        if ($to === 'lost' && trim((string) $lostReason) === '') {
            throw new BusinessRuleException('Adayı "Kaybedildi" olarak işaretlemek için gerekçe girilmelidir.', 'lost_reason_required');
        }
    }

    /** @return array{rate: float, won: int, total: int} */
    public static function conversionRate(int $won, int $total): array
    {
        return ['rate' => $total > 0 ? round($won / $total * 100, 1) : 0.0, 'won' => $won, 'total' => $total];
    }

    /** Kanban sütununda yeniden numaralandırılmış konum listesi: id => sıra */
    public static function positions(array $orderedIds): array
    {
        $positions = [];
        foreach (array_values($orderedIds) as $i => $id) {
            $positions[(int) $id] = $i;
        }

        return $positions;
    }
}
