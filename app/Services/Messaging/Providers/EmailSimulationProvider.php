<?php

namespace App\Services\Messaging\Providers;

use App\Models\OutboundMessage;
use App\Services\Messaging\Contracts\MessageProvider;
use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\TestResult;
use Illuminate\Support\Str;

/** E-posta simülasyonu: SMTP'ye bağlanmaz, hiçbir adrese posta gitmez. Yalnız açıkça seçilirse kullanılır. */
class EmailSimulationProvider implements MessageProvider
{
    public function send(OutboundMessage $message, array $config): ProviderResult
    {
        return ProviderResult::ok('SIM-MAIL-'.Str::upper(Str::random(10)));
    }

    public function testConnection(array $config): TestResult
    {
        return TestResult::ok('Simülasyon kipi — gerçek e-posta gönderilmez.');
    }
}
