<?php

namespace App\Services\Messaging\Sms\Drivers;

use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\Sms\SmsBalance;
use Illuminate\Support\Facades\Http;

/**
 * VatanSMS — JSON API v1 (api_id + api_key).
 *   Gönderim (kişiye özel metin): POST https://api.vatansms.net/api/v1/NtoN
 *       {api_id, api_key, sender, message_type: turkce|normal, message_content_type: bilgi|ticari, phones:[{phone, message}]}
 *       yanıt {"status":"success","id":…} ya da {"status":"error","description":"…"}
 *   Bakiye  : POST …/api/v1/user/information  → data.balance
 *   Başlık  : POST …/api/v1/senders           → data[].sender
 *   Rapor   : POST …/api/v1/report/single     {report_id} → data.phones[] / data[]
 * SAĞLAYICIYLA TEYİT: Unicode kipinin adı, İYS alanları (iys, iys_list), bakiye alan adı (TL mi kredi mi), rapor durum değerleri.
 */
class VatanSmsGateway extends AbstractSmsGateway
{
    public const BASE = 'https://api.vatansms.net/api/v1';

    public function key(): string
    {
        return 'vatansms';
    }

    public function label(): string
    {
        return 'VatanSMS';
    }

    protected function missingConfig(array $config): array
    {
        return array_values(array_filter([
            empty($config['api_id']) ? 'API kimliği (api_id)' : null,
            empty($config['api_key']) ? 'API anahtarı (api_key)' : null,
        ]));
    }

    private static function auth(array $config): array
    {
        return ['api_id' => (string) $config['api_id'], 'api_key' => (string) $config['api_key']];
    }

    public function sendBatch(array $messages, array $config, array $options = []): array
    {
        if ($messages === []) {
            return [];
        }
        if ($missing = $this->missingConfig($config)) {
            return self::all($messages, ProviderResult::fail('VatanSMS ayarı eksik: '.implode(', ', $missing).'.'));
        }
        if (empty($config['header'])) {
            return self::all($messages, ProviderResult::fail('SMS başlığı seçilmemiş.'));
        }

        $commercial = (bool) ($options['commercial'] ?? false);
        $payload = self::auth($config) + [
            'sender' => $config['header'],
            'message_type' => self::mode($config) === 'ascii' ? 'normal' : 'turkce',
            'message_content_type' => $commercial ? 'ticari' : 'bilgi',
            'phones' => array_map(fn ($m) => ['phone' => self::local10($m->to), 'message' => self::text($m, $config)], $messages),
        ];
        if ($commercial) {
            $payload['iys'] = 1;
            $payload['iys_list'] = self::iysRecipientType($config);
        }

        try {
            $response = Http::timeout(20)->acceptJson()->post(self::BASE.'/NtoN', $payload);
        } catch (\Throwable $e) {
            return self::all($messages, ProviderResult::fail(self::connectionError($e, 'VatanSMS')));
        }

        $json = $response->json() ?? [];
        if ($response->successful() && ($json['status'] ?? null) === 'success' && ! empty($json['id'])) {
            $invalid = array_map(fn ($p) => self::local10((string) (is_array($p) ? ($p['phone'] ?? '') : $p)), (array) ($json['invalid_phones'] ?? []));
            $out = [];
            foreach ($messages as $m) {
                $out[$m->id] = in_array(self::local10($m->to), $invalid, true)
                    ? ProviderResult::fail('VatanSMS numarayı geçersiz saydı.')
                    : ProviderResult::ok((string) $json['id']);
            }

            return $out;
        }

        $description = is_string($json['description'] ?? null) ? mb_substr($json['description'], 0, 200) : null;

        return self::all($messages, ProviderResult::fail('VatanSMS gönderimi reddetti'.($description ? ': '.$description : ' (HTTP '.$response->status().')').'.'));
    }

    public function balance(array $config): SmsBalance
    {
        if ($missing = $this->missingConfig($config)) {
            return SmsBalance::fail('VatanSMS ayarı eksik: '.implode(', ', $missing).'.');
        }

        try {
            $response = Http::timeout(15)->acceptJson()->post(self::BASE.'/user/information', self::auth($config));
        } catch (\Throwable $e) {
            return SmsBalance::fail(self::connectionError($e, 'VatanSMS'));
        }

        $json = $response->json() ?? [];
        if (! $response->successful() || ($json['status'] ?? null) !== 'success') {
            $description = is_string($json['description'] ?? null) ? mb_substr($json['description'], 0, 200) : null;

            return SmsBalance::fail('VatanSMS bakiye sorgusu başarısız'.($description ? ': '.$description : ' (HTTP '.$response->status().')').'.');
        }

        $data = (array) ($json['data'] ?? []);
        $money = isset($data['balance']) ? (float) str_replace(',', '.', (string) $data['balance']) : null;
        $credits = isset($data['credit']) ? (float) str_replace(',', '.', (string) $data['credit']) : null;

        return SmsBalance::ok($credits, $money);
    }

    public function originators(array $config): array
    {
        if ($this->missingConfig($config)) {
            return [];
        }

        try {
            $response = Http::timeout(15)->acceptJson()->post(self::BASE.'/senders', self::auth($config));
        } catch (\Throwable) {
            return [];
        }

        $json = $response->json() ?? [];
        if (($json['status'] ?? null) !== 'success') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($row) => is_array($row) ? (string) ($row['sender'] ?? $row['title'] ?? '') : (string) $row,
            (array) ($json['data'] ?? []),
        )));
    }

    public function deliveryReport(array $messages, array $config): array
    {
        if ($this->missingConfig($config)) {
            return [];
        }

        $byReport = [];
        foreach ($messages as $m) {
            if ($m->provider_message_id) {
                $byReport[$m->provider_message_id][] = $m;
            }
        }

        $out = [];
        foreach ($byReport as $reportId => $group) {
            try {
                $response = Http::timeout(20)->acceptJson()->post(self::BASE.'/report/single', self::auth($config) + ['report_id' => (int) $reportId]);
            } catch (\Throwable) {
                continue;
            }
            $json = $response->json() ?? [];
            if (($json['status'] ?? null) !== 'success') {
                continue;
            }

            $rows = $json['data']['phones'] ?? $json['data'] ?? [];
            $statuses = [];
            foreach ((array) $rows as $row) {
                if (is_array($row) && isset($row['phone'])) {
                    $statuses[self::local10((string) $row['phone'])] = mb_strtolower((string) ($row['status'] ?? ''));
                }
            }

            foreach ($group as $m) {
                $s = $statuses[self::local10($m->to)] ?? null;
                if ($s === null) {
                    continue;
                }
                $out[$m->id] = match (true) {
                    in_array($s, ['delivered', 'iletildi', 'success', '1'], true) => ['status' => 'delivered'],
                    in_array($s, ['waiting', 'pending', 'bekliyor', 'sent', 'gönderildi', '0', ''], true) => ['status' => 'pending'],
                    default => ['status' => 'failed', 'error' => 'Sağlayıcı teslim edemedi ('.mb_substr($s, 0, 40).').'],
                };
            }
        }

        return $out;
    }
}
