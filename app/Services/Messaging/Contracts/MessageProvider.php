<?php

namespace App\Services\Messaging\Contracts;

use App\Models\OutboundMessage;
use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\TestResult;

/**
 * Sağlayıcı soyutlaması: WhatsApp (Meta Cloud / genel HTTP), SMS (genel HTTP), E-posta.
 * Her sağlayıcı kendi `Integration->config()` dizisini yapılandırma olarak alır.
 */
interface MessageProvider
{
    public function send(OutboundMessage $message, array $config): ProviderResult;

    public function testConnection(array $config): TestResult;
}
