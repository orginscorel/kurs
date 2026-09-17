<?php

namespace App\Services\Messaging\Sms\Drivers;

use App\Services\Messaging\ProviderResult;
use App\Services\Messaging\Sms\SmsBalance;
use Illuminate\Support\Facades\Http;

/**
 * Mutlucell — XML API (kullanıcı adı `ka`, parola `pwd`).
 *   Gönderim : POST https://smsgw.mutlucell.com/smsgw-ws/sndblkex    <smspack ka pwd org charset><mesaj><metin/><nums/></mesaj>…</smspack>
 *              başarılı yanıt "$<paket-id>#<harcanan kredi>", hatada yalnız sayısal kod (20, 21 …)
 *   Bakiye   : POST …/gtcrdtex   <smskredi ka pwd/>   → "$<kredi>"
 *   Başlık   : POST …/gtorgex    <smsorig ka pwd/>    → "$" + satır satır başlıklar
 *   Rapor    : POST …/gtblkrprtex <smsrapor ka pwd id/> → her satır "<numara> <durum>"
 * SAĞLAYICIYLA TEYİT: İYS öznitelikleri (iys / iysList adları), rapor durum kodlarının anlamı, başlık yanıt biçimi.
 */
class MutlucellGateway extends AbstractSmsGateway
{
    public const BASE = 'https://smsgw.mutlucell.com/smsgw-ws';

    public const ERRORS = [
        '20' => 'Gönderilen istek (XML) eksik ya da hatalı.',
        '21' => 'Seçilen SMS başlığı (originator) hesabınızda tanımlı değil.',
        '22' => 'Kontörünüz (kredi) yetersiz.',
        '23' => 'Kullanıcı adı ya da parola hatalı.',
        '24' => 'Hesabınızda şu anda başka bir işlem sürüyor; biraz sonra yeniden denenecek.',
        '25' => 'Sağlayıcı geçici olarak hizmet dışı (SMSC); biraz sonra yeniden denenecek.',
        '30' => 'Hesap etkinleştirilmemiş.',
    ];

    public function key(): string
    {
        return 'mutlucell';
    }

    public function label(): string
    {
        return 'Mutlucell';
    }

    protected function missingConfig(array $config): array
    {
        return array_values(array_filter([
            empty($config['username']) ? 'kullanıcı adı' : null,
            empty($config['password']) ? 'parola' : null,
        ]));
    }

    public function sendBatch(array $messages, array $config, array $options = []): array
    {
        if ($messages === []) {
            return [];
        }
        if ($missing = $this->missingConfig($config)) {
            return self::all($messages, ProviderResult::fail('Mutlucell ayarı eksik: '.implode(', ', $missing).'.'));
        }
        if (empty($config['header'])) {
            return self::all($messages, ProviderResult::fail('SMS başlığı seçilmemiş.'));
        }

        $attrs = ['ka' => $config['username'], 'pwd' => $config['password'], 'org' => $config['header']];
        $mode = self::mode($config);
        if ($mode === 'tr') {
            $attrs['charset'] = 'turkish';
        } elseif ($mode === 'unicode') {
            $attrs['charset'] = 'unicode';
        }
        if ($options['commercial'] ?? false) {
            $attrs['iys'] = '1';
            $attrs['iysList'] = self::iysRecipientType($config);
            if (! empty($config['iys_brand_code'])) {
                $attrs['iysBrandCode'] = (string) $config['iys_brand_code'];
            }
        } else {
            $attrs['iys'] = '0';
        }

        $body = '';
        foreach ($messages as $m) {
            $body .= '<mesaj><metin>'.self::xml(self::text($m, $config)).'</metin><nums>'.self::xml('90'.self::local10($m->to)).'</nums></mesaj>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><smspack'.self::attrs($attrs).'>'.$body.'</smspack>';

        try {
            $response = Http::timeout(20)->withBody($xml, 'text/xml; charset=UTF-8')->post(self::BASE.'/sndblkex');
        } catch (\Throwable $e) {
            return self::all($messages, ProviderResult::fail(self::connectionError($e, 'Mutlucell')));
        }

        $text = trim($response->body());
        if ($response->successful() && preg_match('/^\$(\d+)#/', $text, $m)) {
            return self::all($messages, ProviderResult::ok($m[1]));
        }

        $code = preg_match('/^\d+$/', $text) ? $text : '';
        $error = self::ERRORS[$code] ?? ('Mutlucell gönderimi reddetti'.($code !== '' ? " (kod {$code})" : " (HTTP {$response->status()})").'.');

        return self::all($messages, ProviderResult::fail($error));
    }

    public function balance(array $config): SmsBalance
    {
        if ($missing = $this->missingConfig($config)) {
            return SmsBalance::fail('Mutlucell ayarı eksik: '.implode(', ', $missing).'.');
        }

        try {
            $response = Http::timeout(15)->withBody('<?xml version="1.0" encoding="UTF-8"?><smskredi'.self::attrs(['ka' => $config['username'], 'pwd' => $config['password']]).' />', 'text/xml; charset=UTF-8')
                ->post(self::BASE.'/gtcrdtex');
        } catch (\Throwable $e) {
            return SmsBalance::fail(self::connectionError($e, 'Mutlucell'));
        }

        $text = trim($response->body());
        if ($response->successful() && preg_match('/^\$([\d.,]+)/', $text, $m)) {
            return SmsBalance::ok((float) str_replace(',', '.', $m[1]));
        }

        return SmsBalance::fail(self::ERRORS[$text] ?? 'Mutlucell bakiye sorgusu başarısız (HTTP '.$response->status().').');
    }

    public function originators(array $config): array
    {
        if ($this->missingConfig($config)) {
            return [];
        }

        try {
            $response = Http::timeout(15)->withBody('<?xml version="1.0" encoding="UTF-8"?><smsorig'.self::attrs(['ka' => $config['username'], 'pwd' => $config['password']]).' />', 'text/xml; charset=UTF-8')
                ->post(self::BASE.'/gtorgex');
        } catch (\Throwable) {
            return [];
        }

        $text = trim($response->body());
        if (! $response->successful() || ! str_starts_with($text, '$')) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', ltrim($text, '$')))));
    }

    public function deliveryReport(array $messages, array $config): array
    {
        if ($this->missingConfig($config)) {
            return [];
        }

        $out = [];
        $byPackage = [];
        foreach ($messages as $m) {
            if ($m->provider_message_id) {
                $byPackage[$m->provider_message_id][] = $m;
            }
        }

        foreach ($byPackage as $packageId => $group) {
            try {
                $response = Http::timeout(20)->withBody('<?xml version="1.0" encoding="UTF-8"?><smsrapor'.self::attrs(['ka' => $config['username'], 'pwd' => $config['password'], 'id' => (string) $packageId]).' />', 'text/xml; charset=UTF-8')
                    ->post(self::BASE.'/gtblkrprtex');
            } catch (\Throwable) {
                continue;
            }
            if (! $response->successful()) {
                continue;
            }

            $statuses = [];
            foreach (preg_split('/\r?\n/', trim($response->body())) as $line) {
                if (preg_match('/^\$?\s*(\d{10,12})\s+(\d+)/', trim($line), $mm)) {
                    $statuses[self::local10($mm[1])] = (int) $mm[2];
                }
            }

            foreach ($group as $m) {
                $status = $statuses[self::local10($m->to)] ?? null;
                if ($status === null) {
                    continue;
                }
                $out[$m->id] = match ($status) {
                    0 => ['status' => 'pending'],
                    1 => ['status' => 'delivered'],
                    default => ['status' => 'failed', 'error' => "Sağlayıcı teslim edemedi (durum {$status})."],
                };
            }
        }

        return $out;
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $k => $v) {
            $out .= ' '.$k.'="'.self::xml((string) $v).'"';
        }

        return $out;
    }
}
