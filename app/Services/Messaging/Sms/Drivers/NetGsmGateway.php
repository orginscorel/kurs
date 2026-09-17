<?php

namespace App\Services\Messaging\Sms\Drivers;

use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\Sms\SmsBalance;
use Illuminate\Support\Facades\Http;

/**
 * NetGSM — REST v2 (Basic Auth: kullanıcı kodu + API alt kullanıcı şifresi).
 *   Gönderim : POST https://api.netgsm.com.tr/sms/rest/v2/send     {msgheader, encoding, iysfilter, messages:[{msg,no}]}
 *   Rapor    : POST https://api.netgsm.com.tr/sms/rest/v2/report   {jobids:[…]}
 *   Başlık   : GET  https://api.netgsm.com.tr/sms/rest/v2/msgheader
 *   Bakiye   : POST https://api.netgsm.com.tr/balance              {usercode, password, stip}
 * Yanıt kodu "00" başarılıdır. iysfilter: "0" bilgilendirme, "11" ticari-bireysel, "12" ticari-tacir.
 * SAĞLAYICIYLA TEYİT: bakiye ucunun stip değeri ve yanıt biçimi, rapor ucunun alan adları.
 */
class NetGsmGateway extends AbstractSmsGateway
{
    public const BASE = 'https://api.netgsm.com.tr';

    public const ERRORS = [
        '20' => 'Mesaj metni hatalı ya da azami uzunluğu aşıyor.',
        '30' => 'Kullanıcı adı/şifre hatalı ya da API erişim izni (IP kısıtı) yok.',
        '40' => 'Mesaj başlığı (gönderici adı) sistemde tanımlı değil.',
        '50' => 'Abone hesabı İYS kontrollü gönderim yapamıyor.',
        '51' => 'İYS marka kodu tanımlı değil.',
        '60' => 'Belirtilen JobID bulunamadı.',
        '70' => 'Hatalı sorgulama: parametrelerden biri hatalı ya da eksik.',
        '80' => 'Gönderim sınırı aşıldı.',
        '85' => 'Mükerrer gönderim sınırı aşıldı (aynı numaraya kısa sürede çok fazla istek).',
    ];

    public function key(): string
    {
        return 'netgsm';
    }

    public function label(): string
    {
        return 'NetGSM';
    }

    protected function missingConfig(array $config): array
    {
        return array_values(array_filter([
            empty($config['username']) ? 'kullanıcı kodu' : null,
            empty($config['password']) ? 'şifre' : null,
        ]));
    }

    public function sendBatch(array $messages, array $config, array $options = []): array
    {
        if ($messages === []) {
            return [];
        }
        if ($missing = $this->missingConfig($config)) {
            return self::all($messages, ProviderResult::fail('NetGSM ayarı eksik: '.implode(', ', $missing).'.'));
        }
        if (empty($config['header'])) {
            return self::all($messages, ProviderResult::fail('SMS başlığı seçilmemiş.'));
        }

        $commercial = (bool) ($options['commercial'] ?? false);
        $payload = [
            'msgheader' => $config['header'],
            'encoding' => self::mode($config) === 'tr' ? 'TR' : '',
            'iysfilter' => $commercial ? (self::iysRecipientType($config) === 'TACIR' ? '12' : '11') : '0',
            'partnercode' => '',
            'appname' => 'ErbaaBilgiEgitim',
            'messages' => array_map(fn ($m) => ['msg' => self::text($m, $config), 'no' => self::local10($m->to)], $messages),
        ];
        if ($commercial && ! empty($config['iys_brand_code'])) {
            $payload['brandcode'] = (string) $config['iys_brand_code']; // SAĞLAYICIYLA TEYİT: alan adı
        }

        try {
            $response = Http::timeout(20)->withBasicAuth($config['username'], $config['password'])->acceptJson()
                ->post(self::BASE.'/sms/rest/v2/send', $payload);
        } catch (\Throwable $e) {
            return self::all($messages, ProviderResult::fail(self::connectionError($e, 'NetGSM')));
        }

        $json = $response->json() ?? [];
        $code = (string) ($json['code'] ?? '');
        if ($response->successful() && $code === '00' && ! empty($json['jobid'])) {
            return self::all($messages, ProviderResult::ok((string) $json['jobid']));
        }

        $error = self::ERRORS[$code] ?? ('NetGSM gönderimi reddetti'.($code !== '' ? " (kod {$code})" : " (HTTP {$response->status()})").'.');

        return self::all($messages, ProviderResult::fail($error));
    }

    public function balance(array $config): SmsBalance
    {
        if ($missing = $this->missingConfig($config)) {
            return SmsBalance::fail('NetGSM ayarı eksik: '.implode(', ', $missing).'.');
        }

        try {
            $response = Http::timeout(15)->acceptJson()->post(self::BASE.'/balance', [
                'usercode' => $config['username'], 'password' => $config['password'], 'stip' => 2,
            ]);
        } catch (\Throwable $e) {
            return SmsBalance::fail(self::connectionError($e, 'NetGSM'));
        }

        $json = $response->json();
        if (! is_array($json)) {
            return SmsBalance::fail('NetGSM bakiye yanıtı okunamadı (HTTP '.$response->status().').');
        }
        $code = (string) ($json['code'] ?? '');
        if ($code !== '' && $code !== '00') {
            return SmsBalance::fail(self::ERRORS[$code] ?? "NetGSM bakiye sorgusu reddedildi (kod {$code}).");
        }

        $credits = null;
        foreach ((array) ($json['balance'] ?? []) as $item) {
            if (is_array($item) && str_contains(mb_strtolower((string) ($item['balance_name'] ?? '')), 'sms')) {
                $credits = ($credits ?? 0) + (float) str_replace(',', '.', (string) ($item['amount'] ?? 0));
            }
        }
        $money = isset($json['balance']) && is_scalar($json['balance']) ? (float) str_replace(',', '.', (string) $json['balance']) : null;

        if ($credits === null && $money === null && ! $response->successful()) {
            return SmsBalance::fail('NetGSM bakiye sorgusu başarısız (HTTP '.$response->status().').');
        }

        return SmsBalance::ok($credits, $money);
    }

    public function originators(array $config): array
    {
        if ($this->missingConfig($config)) {
            return [];
        }

        try {
            $response = Http::timeout(15)->withBasicAuth($config['username'], $config['password'])->acceptJson()
                ->get(self::BASE.'/sms/rest/v2/msgheader');
        } catch (\Throwable) {
            return [];
        }

        $json = $response->json() ?? [];
        if ((string) ($json['code'] ?? '') !== '00') {
            return [];
        }

        return array_values(array_filter(array_map('strval', (array) ($json['msgheaders'] ?? []))));
    }

    public function deliveryReport(array $messages, array $config): array
    {
        $jobIds = array_values(array_unique(array_filter(array_map(fn ($m) => $m->provider_message_id, $messages))));
        if ($jobIds === [] || $this->missingConfig($config)) {
            return [];
        }

        try {
            $response = Http::timeout(20)->withBasicAuth($config['username'], $config['password'])->acceptJson()
                ->post(self::BASE.'/sms/rest/v2/report', ['jobids' => $jobIds]);
        } catch (\Throwable) {
            return [];
        }

        $json = $response->json() ?? [];
        if ((string) ($json['code'] ?? '') !== '00') {
            return [];
        }

        // (jobid, numara) → durum
        $map = [];
        foreach ((array) ($json['jobs'] ?? []) as $job) {
            $number = self::local10((string) ($job['telno'] ?? $job['number'] ?? ''));
            $map[($job['jobid'] ?? '').'|'.$number] = (int) ($job['status'] ?? 0);
        }

        $out = [];
        foreach ($messages as $m) {
            $status = $map[$m->provider_message_id.'|'.self::local10($m->to)] ?? null;
            if ($status === null) {
                continue;
            }
            $out[$m->id] = match ($status) {
                0 => ['status' => 'pending'],
                1 => ['status' => 'delivered'],
                default => ['status' => 'failed', 'error' => self::reportError($status)],
            };
        }

        return $out;
    }

    public static function reportError(int $status): string
    {
        return match ($status) {
            2 => 'Zaman aşımı: mesaj alıcıya iletilemedi.',
            3 => 'Hatalı ya da kullanılmayan numara.',
            4 => 'Operatöre gönderilemedi.',
            11 => 'Operatör mesajı kabul etmedi.',
            12 => 'Gönderim hatası.',
            13 => 'Mükerrer gönderim.',
            15 => 'Numara kara listede (ret etmiş).',
            16 => 'İYS: alıcının ticari ileti izni yok.',
            17 => 'İYS kontrolü hatası.',
            default => "Sağlayıcı teslim edemedi (durum {$status}).",
        };
    }
}
