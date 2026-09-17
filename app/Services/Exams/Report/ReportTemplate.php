<?php

namespace App\Services\Exams\Report;

/**
 * Görsel rapor şablonu. Yeni rapor türü (karne, devamsızlık özeti, hedef takibi…)
 * eklemek için bu arayüzü uygulayın ve ReportCardRenderer::withTemplate ile kullanın.
 */
interface ReportTemplate
{
    /** Tuval boyutu [genişlik, yükseklik] */
    public function size(): array;

    /** @param array<string,mixed> $data */
    public function draw(Canvas $canvas, array $data): void;
}
