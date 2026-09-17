<?php

namespace App\Services\Messaging\Providers;

use App\Models\OutboundMessage;
use App\Services\Messaging\Contracts\MessageProvider;
use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\TestResult;

/**
 * Genel HTTP SMS sağlayıcısı. `provider` alanı 'netgsm' ise config'e NetGSM uyumlu
 * varsayılanlar (URL, gövde şablonu) uygulanır; kullanıcı yine de üzerine yazabilir.
 */
class SmsGenericHttpProvider implements MessageProvider
{
    public function send(OutboundMessage $message, array $config): ProviderResult
    {
        return GenericHttpSender::send($message, $this->withPreset($config), 'SMS');
    }

    public function testConnection(array $config): TestResult
    {
        return GenericHttpSender::test($this->withPreset($config), 'SMS');
    }

    private function withPreset(array $config): array
    {
        if (($config['preset'] ?? null) !== 'netgsm') {
            return $config;
        }

        return array_merge([
            'url' => 'https://api.netgsm.com.tr/sms/send/get',
            'method' => 'GET',
            'query_template' => [
                'usercode' => $config['username'] ?? '',
                'password' => $config['password'] ?? '',
                'gsmno' => '{{to}}',
                'message' => '{{body}}',
                'msgheader' => $config['header'] ?? '',
            ],
        ], $config);
    }
}
