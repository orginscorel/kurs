<?php

namespace Tests\Unit;

use App\Models\OutboundMessage;
use App\Services\Campaigns\CampaignText;
use App\Services\Campaigns\UnsubscribeToken;
use App\Services\Messaging\Sms\Drivers\MutlucellGateway;
use App\Services\Messaging\Sms\Drivers\NetGsmGateway;
use App\Services\Messaging\Sms\Drivers\SimulationGateway;
use App\Services\Messaging\Sms\Drivers\VatanSmsGateway;
use App\Services\Messaging\Sms\SmsLength;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SMS uzunluk/parça hesabı, sağlayıcı sürücülerinin istek/yanıt ayrıştırması (Http::fake — gerçek istek YOK),
 * değişken işleme ve abonelik jetonu. Veritabanı kullanmaz.
 */
class MessageCampaignDriversTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function msg(int $id, string $to, string $body, bool $commercial = false, ?string $providerId = null): OutboundMessage
    {
        $m = new OutboundMessage(['to' => $to, 'body' => $body, 'channel' => 'sms', 'is_commercial' => $commercial, 'provider_message_id' => $providerId]);
        $m->id = $id;

        return $m;
    }

    // ------------------------------------------------------------ SMS uzunluğu

    public function test_gsm7_limits_160_and_153(): void
    {
        $this->assertSame(['gsm7', 160, 1], $this->a(str_repeat('a', 160)));
        $this->assertSame(['gsm7', 161, 2], $this->a(str_repeat('a', 161)));
        $this->assertSame(['gsm7', 306, 2], $this->a(str_repeat('a', 306)));
        $this->assertSame(['gsm7', 307, 3], $this->a(str_repeat('a', 307)));
    }

    public function test_turkish_characters_use_turkish_table_155_150(): void
    {
        $this->assertSame(['tr', 155, 1], $this->a('ş'.str_repeat('a', 154)));
        $this->assertSame(['tr', 156, 2], $this->a('ş'.str_repeat('a', 155)));
        $this->assertSame(['tr', 301, 3], $this->a('ğ'.str_repeat('a', 300)));
        // Ç, Ö, Ü, ö, ü GSM temel tablosunda: Türkçe tabloya geçmez
        $this->assertSame('gsm7', SmsLength::analyze('ÇÖÜöü')['encoding']);
    }

    public function test_unicode_limits_70_and_67_and_emoji_counts_two(): void
    {
        $this->assertSame(['unicode', 70, 1], $this->a(str_repeat('ş', 70), 'unicode'));
        $this->assertSame(['unicode', 71, 2], $this->a(str_repeat('ş', 71), 'unicode'));
        $this->assertSame(['unicode', 135, 3], $this->a(str_repeat('a', 135), 'unicode'));
        // Türkçe kipte tabloda olmayan karakter (emoji) → Unicode, emoji 2 birim
        $r = SmsLength::analyze('Merhaba 🎉', 'tr');
        $this->assertSame('unicode', $r['encoding']);
        $this->assertSame(10, $r['length']);
    }

    public function test_extension_characters_count_double_and_ascii_mode_transliterates(): void
    {
        $this->assertSame(['gsm7', 7, 1], $this->a('a€[]'));
        $r = SmsLength::analyze('Şükrü Işık öğretmen', 'ascii');
        $this->assertSame('Sukru Isik ogretmen', $r['text']);
        $this->assertSame('gsm7', $r['encoding']);
        $this->assertSame(0, SmsLength::analyze('')['parts']);
    }

    /** @return array{0:string,1:int,2:int} */
    private function a(string $text, string $mode = 'tr'): array
    {
        $r = SmsLength::analyze($text, $mode);

        return [$r['encoding'], $r['length'], $r['parts']];
    }

    // ------------------------------------------------------------ NetGSM

    public function test_netgsm_sends_batch_with_basic_auth_and_iys_filter(): void
    {
        Http::fake(['api.netgsm.com.tr/sms/rest/v2/send' => Http::response(['code' => '00', 'jobid' => 'J123', 'description' => 'queued'])]);

        $gw = new NetGsmGateway;
        $out = $gw->sendBatch([$this->msg(1, '905321112233', 'Merhaba Ayşe', true), $this->msg(2, '905321112244', 'Merhaba Ali', true)],
            ['username' => '8501234567', 'password' => 'gizli-sifre', 'header' => 'ERBAABILGI', 'encoding' => 'tr', 'iys_brand_code' => '123456'],
            ['commercial' => true]);

        $this->assertTrue($out[1]->success);
        $this->assertSame('J123', $out[2]->providerMessageId);
        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return $r->hasHeader('Authorization', 'Basic '.base64_encode('8501234567:gizli-sifre'))
                && $body['msgheader'] === 'ERBAABILGI' && $body['encoding'] === 'TR' && $body['iysfilter'] === '11'
                && $body['messages'][0] === ['msg' => 'Merhaba Ayşe', 'no' => '5321112233']
                && ! str_contains($r->url(), 'gizli-sifre');
        });
    }

    public function test_netgsm_informational_filter_and_error_codes(): void
    {
        Http::fake(['api.netgsm.com.tr/sms/rest/v2/send' => Http::response(['code' => '40', 'description' => 'msgheader'])]);

        $out = (new NetGsmGateway)->sendBatch([$this->msg(7, '905321112233', 'Duyuru')], ['username' => 'u', 'password' => 'p', 'header' => 'X', 'iys_recipient_type' => 'TACIR']);

        $this->assertFalse($out[7]->success);
        $this->assertStringContainsString('başlığı', $out[7]->error);
        Http::assertSent(fn (Request $r) => $r->data()['iysfilter'] === '0');
    }

    public function test_netgsm_connection_error_never_leaks_credentials(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.netgsm.com.tr?password=gizli-sifre'));

        $out = (new NetGsmGateway)->sendBatch([$this->msg(1, '905321112233', 'x')], ['username' => 'u', 'password' => 'gizli-sifre', 'header' => 'X']);

        $this->assertFalse($out[1]->success);
        $this->assertStringNotContainsString('gizli', $out[1]->error);
    }

    public function test_netgsm_missing_config_and_header_do_not_call_api(): void
    {
        Http::fake();
        $gw = new NetGsmGateway;
        $this->assertFalse($gw->sendBatch([$this->msg(1, '905321112233', 'x')], ['username' => 'u'])[1]->success);
        $this->assertFalse($gw->sendBatch([$this->msg(1, '905321112233', 'x')], ['username' => 'u', 'password' => 'p'])[1]->success);
        $this->assertFalse($gw->testConnection([])->success);
        Http::assertNothingSent();
    }

    public function test_netgsm_balance_and_delivery_report(): void
    {
        Http::fake([
            'api.netgsm.com.tr/balance' => Http::response(['balance' => [['amount' => '1500', 'balance_name' => 'Adet SMS'], ['amount' => '10', 'balance_name' => 'Adet MMS']]]),
            'api.netgsm.com.tr/sms/rest/v2/report' => Http::response(['code' => '00', 'jobs' => [
                ['jobid' => 'J1', 'telno' => '5321112233', 'status' => 1],
                ['jobid' => 'J1', 'telno' => '5321112244', 'status' => 16],
                ['jobid' => 'J1', 'telno' => '5321112255', 'status' => 0],
            ]]),
        ]);
        $gw = new NetGsmGateway;
        $config = ['username' => 'u', 'password' => 'p'];

        $balance = $gw->balance($config);
        $this->assertTrue($balance->success);
        $this->assertSame(1500.0, $balance->credits);
        $this->assertTrue($gw->testConnection($config)->success);

        $report = $gw->deliveryReport([
            $this->msg(1, '905321112233', 'x', false, 'J1'), $this->msg(2, '905321112244', 'x', false, 'J1'), $this->msg(3, '905321112255', 'x', false, 'J1'),
        ], $config);
        $this->assertSame('delivered', $report[1]['status']);
        $this->assertSame('failed', $report[2]['status']);
        $this->assertStringContainsString('İYS', $report[2]['error']);
        $this->assertSame('pending', $report[3]['status']);
    }

    // ------------------------------------------------------------ Mutlucell

    public function test_mutlucell_builds_escaped_xml_and_parses_package_id(): void
    {
        Http::fake(['smsgw.mutlucell.com/smsgw-ws/sndblkex' => Http::response('$4455667#2.0')]);

        $out = (new MutlucellGateway)->sendBatch([$this->msg(1, '905321112233', 'Ali & Veli <kurs> "ş"', true)],
            ['username' => 'kurum', 'password' => 'p<w', 'header' => 'ERBAA', 'encoding' => 'tr'], ['commercial' => true]);

        $this->assertTrue($out[1]->success);
        $this->assertSame('4455667', $out[1]->providerMessageId);
        Http::assertSent(function (Request $r) {
            $xml = $r->body();

            return str_contains($xml, 'ka="kurum"') && str_contains($xml, 'pwd="p&lt;w"') && str_contains($xml, 'charset="turkish"')
                && str_contains($xml, 'iys="1"') && str_contains($xml, 'iysList="BIREYSEL"')
                && str_contains($xml, '<metin>Ali &amp; Veli &lt;kurs&gt; &quot;ş&quot;</metin>')
                && str_contains($xml, '<nums>905321112233</nums>')
                && simplexml_load_string($xml) !== false;
        });
    }

    public function test_mutlucell_error_code_and_balance(): void
    {
        Http::fake([
            'smsgw.mutlucell.com/smsgw-ws/sndblkex' => Http::response('23'),
            'smsgw.mutlucell.com/smsgw-ws/gtcrdtex' => Http::response('$1250.5'),
            'smsgw.mutlucell.com/smsgw-ws/gtorgex' => Http::response("\$ERBAA\nERBAABILGI"),
        ]);
        $gw = new MutlucellGateway;
        $config = ['username' => 'k', 'password' => 'p', 'header' => 'ERBAA'];

        $out = $gw->sendBatch([$this->msg(1, '905321112233', 'x')], $config);
        $this->assertFalse($out[1]->success);
        $this->assertStringContainsString('parola', $out[1]->error);
        $this->assertSame(1250.5, $gw->balance($config)->credits);
        $this->assertSame(['ERBAA', 'ERBAABILGI'], $gw->originators($config));
    }

    // ------------------------------------------------------------ VatanSMS

    public function test_vatansms_nton_payload_and_invalid_phones(): void
    {
        Http::fake(['api.vatansms.net/api/v1/NtoN' => Http::response(['status' => 'success', 'id' => 991, 'invalid_phones' => ['5321112244']])]);

        $out = (new VatanSmsGateway)->sendBatch([$this->msg(1, '905321112233', 'Bir'), $this->msg(2, '905321112244', 'İki')],
            ['api_id' => 'ID', 'api_key' => 'KEY', 'header' => 'ERBAA', 'encoding' => 'tr']);

        $this->assertTrue($out[1]->success);
        $this->assertSame('991', $out[1]->providerMessageId);
        $this->assertFalse($out[2]->success);
        Http::assertSent(fn (Request $r) => $r['message_type'] === 'turkce' && $r['message_content_type'] === 'bilgi'
            && $r['phones'][1] === ['phone' => '5321112244', 'message' => 'İki'] && ! isset($r['iys']));
    }

    public function test_vatansms_error_and_balance(): void
    {
        Http::fake([
            'api.vatansms.net/api/v1/NtoN' => Http::response(['status' => 'error', 'description' => 'Yetersiz bakiye'], 400),
            'api.vatansms.net/api/v1/user/information' => Http::response(['status' => 'success', 'data' => ['balance' => '45.20']]),
        ]);
        $gw = new VatanSmsGateway;
        $out = $gw->sendBatch([$this->msg(1, '905321112233', 'x')], ['api_id' => 'a', 'api_key' => 'b', 'header' => 'H'], ['commercial' => true]);
        $this->assertStringContainsString('Yetersiz bakiye', $out[1]->error);
        $this->assertSame(45.2, $gw->balance(['api_id' => 'a', 'api_key' => 'b'])->money);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'NtoN') ? $r['message_content_type'] === 'ticari' && $r['iys'] === 1 : true);
    }

    // ------------------------------------------------------------ Simülasyon

    public function test_simulation_never_calls_http(): void
    {
        Http::fake();
        $gw = new SimulationGateway;
        $out = $gw->sendBatch([$this->msg(1, '905321112233', 'x'), $this->msg(2, '905551112233', 'y')], ['fail_numbers' => '05551112233']);

        $this->assertTrue($out[1]->success);
        $this->assertStringStartsWith('SIM-', $out[1]->providerMessageId);
        $this->assertFalse($out[2]->success);
        $this->assertTrue($gw->testConnection([])->success);
        $this->assertSame('delivered', $gw->deliveryReport([$this->msg(1, '905321112233', 'x', false, 'SIM-1')], [])[1]['status']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ Değişkenler + jeton

    public function test_variables_render_with_both_syntaxes_and_aliases(): void
    {
        $vars = ['ad' => 'Ayşe', 'ogrenci_ad' => 'Ali Kaya', 'kurum' => 'Erbaa Bilgi', 'ad_soyad' => 'Ayşe Kaya'];
        $this->assertSame('Sayın Ayşe, Ali Kaya için Erbaa Bilgi. Ayşe Kaya',
            CampaignText::render('Sayın {ad}, {{ogrenci_adi}} için {kurum}. {{veli_adi}}', $vars));
        $this->assertSame(['bilinmez'], CampaignText::unknown('Merhaba {ad} {bilinmez}'));
        $this->assertSame('{bilinmez} kalır', CampaignText::render('{bilinmez} kalır', $vars));
    }

    public function test_sms_opt_out_text(): void
    {
        $this->assertNull(CampaignText::smsOptOut([]));
        $this->assertSame('SMS almamak için RET yazıp 4609 numarasına ücretsiz gönderin. B001 Mersis: 0123',
            CampaignText::smsOptOut(['ret_number' => '4609', 'ret_code' => 'B001', 'mersis_no' => '0123']));
    }

    public function test_unsubscribe_token_roundtrip_and_tamper(): void
    {
        $token = UnsubscribeToken::make(1, 'email', 'guardian', 42, 'veli@ornek.com');
        $data = UnsubscribeToken::parse($token);
        $this->assertSame(['branch_id' => 1, 'channel' => 'email', 'recipient_type' => 'guardian', 'recipient_id' => 42, 'address' => 'veli@ornek.com'], $data);

        [$payload, $sig] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode(json_encode(['b' => 1, 'c' => 'email', 't' => 'guardian', 'i' => 43, 'a' => 'baska@ornek.com'])), '+/', '-_'), '=');
        $this->assertNull(UnsubscribeToken::parse($forged.'.'.$sig));
        $this->assertNull(UnsubscribeToken::parse($payload.'.'.str_repeat('0', 32)));
        $this->assertNull(UnsubscribeToken::parse('bozuk'));
        $this->assertStringContainsString('/api/v1/abonelik/', UnsubscribeToken::url($token));
    }
}
