<?php

namespace Tests\Unit;

use App\Services\Devices\Analysis\HarSanitizer;
use Tests\TestCase;

/** Cihaz web paneli HAR kaydı: gizli bilgiler silinir, uç noktalar ve JS içeriği korunur. */
class PanelHarTest extends TestCase
{
    public function test_sanitizer_removes_secrets_and_keeps_endpoints(): void
    {
        $har = ['log' => ['entries' => [
            ['request' => ['method' => 'POST', 'url' => 'http://192.168.68.60/cgi-bin/login?user=admin&password=Gizli123',
                'headers' => [['name' => 'Cookie', 'value' => 'sid=abc'], ['name' => 'Authorization', 'value' => 'Basic eHg='], ['name' => 'Content-Type', 'value' => 'application/json']],
                'cookies' => [['name' => 'sid', 'value' => 'abc']],
                'queryString' => [['name' => 'password', 'value' => 'Gizli123']],
                'postData' => ['text' => '{"username":"admin","password":"Gizli123"}']],
             'response' => ['headers' => [['name' => 'Set-Cookie', 'value' => 'sid=xyz']], 'content' => ['text' => 'ok']]],
            ['request' => ['method' => 'GET', 'url' => 'http://192.168.68.60/js/user.js', 'headers' => []],
             'response' => ['headers' => [], 'content' => ['text' => 'function saveUser(){ return post("/cgi-bin/setUserInfo", {id, name}) }']]],
        ]]];

        $t = (new HarSanitizer)->temizle(json_encode($har));

        $this->assertSame(2, $t['istek']);
        $this->assertSame(['192.168.68.60'], $t['hostlar']);
        foreach (['Gizli123', 'sid=abc', 'sid=xyz', 'Basic eHg='] as $gizli) {
            $this->assertStringNotContainsString($gizli, $t['json']);
        }
        $this->assertStringContainsString('/cgi-bin/setUserInfo', $t['json']);   // JS içindeki uç korunur
        $this->assertStringContainsString('application/json', $t['json']);
    }
}
