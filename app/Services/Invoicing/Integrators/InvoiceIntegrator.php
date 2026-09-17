<?php

namespace App\Services\Invoicing\Integrators;

use App\Models\Invoice;

/**
 * e-Fatura / e-Arşiv entegratör sürücüsü arayüzü. Özel entegratör (GİB onaylı) bağlandığında
 * bu arayüzü uygulayan bir sürücü yazılır ve `accounting.integrator` ayarıyla seçilir.
 * Şu an gerçek gönderim YOKTUR.
 */
interface InvoiceIntegrator
{
    public function key(): string;

    public function label(): string;

    /** Gerçek bir entegratöre bağlı mı? */
    public function connected(): bool;

    /** @return array{status:string, message:string, ettn?:?string} */
    public function send(Invoice $invoice): array;

    /** @return array{status:string, message:string} */
    public function cancel(Invoice $invoice): array;
}
