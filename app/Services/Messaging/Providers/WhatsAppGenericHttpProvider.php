<?php

namespace App\Services\Messaging\Providers;

use App\Models\OutboundMessage;
use App\Services\Messaging\Contracts\MessageProvider;
use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\TestResult;
use Illuminate\Support\Facades\Http;

/**
 * Yapılandırılabilir genel HTTP WhatsApp sağlayıcısı (Meta dışı API'ler: ör. yerel/üçüncü parti köprüler).
 * config: url, method (POST varsayılan), headers {k:v}, body_template (JSON string, {{to}}/{{body}} yer tutucu),
 *         test_url (opsiyonel, boşsa `url` kullanılır), success_path (yanıtta başarı alanı, ör. "status")
 */
class WhatsAppGenericHttpProvider implements MessageProvider
{
    public function send(OutboundMessage $message, array $config): ProviderResult
    {
        return GenericHttpSender::send($message, $config, 'WhatsApp');
    }

    public function testConnection(array $config): TestResult
    {
        return GenericHttpSender::test($config, 'WhatsApp');
    }
}
