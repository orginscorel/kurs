<?php

namespace App\Services\Invoicing\Integrators;

use App\Models\Invoice;

/** Varsayılan: entegratör bağlı değil. Fatura yalnız kurum içinde kesilir; GİB'e iletilmez. */
class NullIntegrator implements InvoiceIntegrator
{
    public function key(): string
    {
        return 'none';
    }

    public function label(): string
    {
        return 'Bağlı değil';
    }

    public function connected(): bool
    {
        return false;
    }

    public function send(Invoice $invoice): array
    {
        return ['status' => 'not_sent', 'message' => 'e-Fatura/e-Arşiv entegratörü bağlı değil; fatura GİB\'e iletilmedi. Portaldan elle düzenleyin.'];
    }

    public function cancel(Invoice $invoice): array
    {
        return ['status' => 'not_sent', 'message' => 'Entegratör bağlı değil; iptali GİB portalında da yapın.'];
    }
}
