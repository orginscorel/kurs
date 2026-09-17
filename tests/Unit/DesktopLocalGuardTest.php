<?php

namespace Tests\Unit;

use App\Http\Middleware\DesktopLocalGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/** Masaüstü yerel sunucu koruması: yalnız KURS_NODE=local + jeton özeti tanımlıyken devrede. */
class DesktopLocalGuardTest extends TestCase
{
    private string $token = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6abcd';

    private function guard(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return (new DesktopLocalGuard)->handle($request, fn () => new Response('ok'));
    }

    private function local(): void
    {
        config(['kurs.node' => 'local', 'desktop.token_hash' => hash('sha256', $this->token)]);
    }

    public function test_server_node_is_untouched(): void
    {
        config(['kurs.node' => 'server', 'desktop.token_hash' => hash('sha256', $this->token)]);
        $res = $this->guard(Request::create('http://kurs.example.com/api/v1/auth/me'));
        $this->assertSame('ok', $res->getContent());
    }

    public function test_local_without_hash_is_untouched(): void
    {
        config(['kurs.node' => 'local', 'desktop.token_hash' => null]);
        $this->assertSame('ok', $this->guard(Request::create('http://127.0.0.1:5000/'))->getContent());
    }

    public function test_request_without_token_is_denied(): void
    {
        $this->local();
        $this->assertSame(403, $this->guard(Request::create('http://127.0.0.1:5000/api/v1/auth/me'))->getStatusCode());
    }

    public function test_wrong_token_is_denied(): void
    {
        $this->local();
        $req = Request::create('http://127.0.0.1:5000/', 'GET', [], ['kurs_desktop' => str_repeat('0', 64)]);
        $this->assertSame(403, $this->guard($req)->getStatusCode());
    }

    public function test_foreign_host_is_denied_even_with_token(): void
    {
        $this->local();
        $req = Request::create('http://evil.example.com/', 'GET', [], ['kurs_desktop' => $this->token]);
        $this->assertSame(421, $this->guard($req)->getStatusCode());
    }

    public function test_cookie_or_header_token_passes(): void
    {
        $this->local();
        $viaCookie = Request::create('http://127.0.0.1:5000/api/v1/auth/me', 'GET', [], ['kurs_desktop' => $this->token]);
        $this->assertSame('ok', $this->guard($viaCookie)->getContent());

        $viaHeader = Request::create('http://localhost:5000/up');
        $viaHeader->headers->set('X-Kurs-Desktop', $this->token);
        $this->assertSame('ok', $this->guard($viaHeader)->getContent());
    }

    public function test_boot_sets_cookie_and_redirects_only_to_local_paths(): void
    {
        $this->local();
        $res = $this->guard(Request::create('http://127.0.0.1:5000/__desktop/boot', 'GET', ['t' => $this->token, 'next' => '//evil.example.com']));
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('http://127.0.0.1:5000', rtrim((string) $res->headers->get('Location'), '/'));
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === 'kurs_desktop');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', strtolower((string) $cookie->getSameSite()));

        $bad = $this->guard(Request::create('http://127.0.0.1:5000/__desktop/boot', 'GET', ['t' => 'yanlis']));
        $this->assertSame(403, $bad->getStatusCode());
    }
}
