<?php

namespace App\Services\Messaging\Sms\Drivers;

use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\Sms\SmsBalance;
use Illuminate\Support\Str;

/**
 * Simülasyon (test) sürücüsü: HİÇBİR YERE istek atmaz. Mesajlar "gönderildi (simülasyon)" olur,
 * teslim raporunda "iletildi" döner. Yalnız entegrasyonda açıkça seçilirse çalışır.
 * config.fail_numbers (virgülle) içindeki numaralar başarısız sayılır — hata akışını denemek için.
 */
class SimulationGateway extends AbstractSmsGateway
{
    public function key(): string
    {
        return 'simulation';
    }

    public function label(): string
    {
        return 'Simülasyon';
    }

    protected function missingConfig(array $config): array
    {
        return [];
    }

    public function sendBatch(array $messages, array $config, array $options = []): array
    {
        $failNumbers = array_filter(array_map(fn ($n) => self::local10(trim($n)), explode(',', (string) ($config['fail_numbers'] ?? ''))));
        $batchId = 'SIM-'.Str::upper(Str::random(10));
        $out = [];
        foreach ($messages as $m) {
            $out[$m->id] = in_array(self::local10($m->to), $failNumbers, true)
                ? ProviderResult::fail('Simülasyon: bu numara başarısız olarak ayarlandı.')
                : ProviderResult::ok($batchId);
        }

        return $out;
    }

    public function balance(array $config): SmsBalance
    {
        return SmsBalance::ok(null, null, 'Simülasyon kipi — gerçek gönderim yapılmaz');
    }

    public function originators(array $config): array
    {
        return ['SIMULASYON'];
    }

    public function deliveryReport(array $messages, array $config): array
    {
        $out = [];
        foreach ($messages as $m) {
            $out[$m->id] = ['status' => 'delivered'];
        }

        return $out;
    }
}
