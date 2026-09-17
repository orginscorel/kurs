<?php

namespace App\Services\Messaging\Contracts;

use App\Models\OutboundMessage;
use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\Sms\SmsBalance;

/**
 * SMS sağlayıcı sürücüsü (NetGSM, Mutlucell, VatanSMS, Simülasyon).
 * `MessageProvider::send()` tekli gönderimdir (otomasyon/duyuru); toplu gönderim `sendBatch()` ile
 * tek istekte yapılır. `testConnection()` bakiye sorgusuyla bağlantıyı doğrular.
 *
 * config: username/password (ya da api_id/api_key), header (başlık), encoding (tr|unicode|ascii),
 *         iys_brand_code, iys_recipient_type (BIREYSEL|TACIR) …
 */
interface SmsGateway extends MessageProvider
{
    /** Sağlayıcı kodu (integrations.provider). */
    public function key(): string;

    public function label(): string;

    /**
     * Aynı başlık + aynı ticari/bilgilendirme türündeki mesajları tek istekte gönderir.
     * Mesajların gövdesi farklı olabilir (kişiselleştirme).
     *
     * @param  list<OutboundMessage>  $messages
     * @param  array{commercial?: bool}  $options
     * @return array<int, ProviderResult> OutboundMessage id => sonuç
     */
    public function sendBatch(array $messages, array $config, array $options = []): array;

    public function balance(array $config): SmsBalance;

    /** @return list<string> kayıtlı ve onaylı SMS başlıkları */
    public function originators(array $config): array;

    /**
     * Teslim raporu.
     *
     * @param  list<OutboundMessage>  $messages  provider_message_id'si olan gönderilmiş mesajlar
     * @return array<int, array{status: 'delivered'|'failed'|'pending', error?: ?string}> OutboundMessage id => durum
     */
    public function deliveryReport(array $messages, array $config): array;

    /** Tek istekte gönderilebilecek en fazla alıcı. */
    public function maxBatchSize(): int;
}
