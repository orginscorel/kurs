<?php

namespace App\Services\Invoicing\Integrators;

use App\Models\Invoice;
use Illuminate\Support\Str;

/** Deneme sürücüsü: ağ bağlantısı YOK; yalnız akışı göstermek için "simüle edildi" durumu ve örnek ETTN üretir. */
class SimulationIntegrator implements InvoiceIntegrator
{
    public function key(): string
    {
        return 'simulation';
    }

    public function label(): string
    {
        return 'Simülasyon (gerçek gönderim yok)';
    }

    public function connected(): bool
    {
        return false;
    }

    public function send(Invoice $invoice): array
    {
        return ['status' => 'simulated', 'message' => 'Simülasyon: fatura entegratöre gönderilmiş gibi işaretlendi (gerçek gönderim yapılmadı).', 'ettn' => $invoice->ettn ?: (string) Str::uuid()];
    }

    public function cancel(Invoice $invoice): array
    {
        return ['status' => 'simulated_cancel', 'message' => 'Simülasyon: iptal bildirimi gönderilmiş gibi işaretlendi.'];
    }
}
