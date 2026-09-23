<?php

namespace Tests\Unit;

use App\Sync\Local\LocalState;
use App\Sync\Local\SyncClient;
use App\Sync\Local\SyncHttpException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Yerel düğüm sunucu istemcisi (SyncClient) ve durum göstergesi (LocalState) — uygulama açılışından/uykudan hemen
 * sonraki GEÇİCİ bağlantı kesintilerinin kullanıcıya yanlışlıkla "internet yok" gibi görünmemesi.
 */
class SyncClientRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.connections.sqlite.database') !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }
        config(['sync.server_url' => 'https://kurs.example.test', 'sync.device_token' => 'test-token']);
        Storage::fake('local');
        $this->artisan('migrate', ['--force' => true])->run();
    }

    private function client(): SyncClient
    {
        return new SyncClient(app(LocalState::class));
    }

    public function test_baglanti_kurulamadi_hatasinda_bir_kez_yeniden_dener_ve_basarili_olur(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('cURL error 6: Could not resolve host: kurs.example.test');
            }

            return Http::response(['ok' => true, 'protocol' => 1], 200);
        });

        $res = $this->client()->ping();

        $this->assertSame(2, $calls, 'İlk denemede host çözülemedi; ikinci deneme yapılmalıydı.');
        $this->assertTrue($res['ok']);
    }

    public function test_belirsiz_hatada_yeniden_denemez(): void
    {
        // Okuma zaman aşımı: istek sunucuya ulaşmış olabilir; çift uygulama riskine karşı YENİDEN DENENMEZ.
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: Operation timed out after 30000 ms');
        });

        try {
            $this->client()->ping();
            $this->fail('Çevrimdışı istisnası bekleniyordu.');
        } catch (SyncHttpException $e) {
            $this->assertTrue($e->isOffline());
        }
        $this->assertSame(1, $calls, 'Belirsiz (zaman aşımı) hata yeniden denenmemeliydi.');
    }

    public function test_gecici_kesinti_iki_kez_surerse_cevrimdisi_dondurur(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 7: Failed to connect to kurs.example.test port 443: Connection refused');
        });

        $this->expectException(SyncHttpException::class);
        try {
            $this->client()->ping();
        } finally {
            $this->assertSame(2, $calls, 'Bağlantı hatası tam olarak bir kez yeniden denenmeliydi.');
        }
    }

    public function test_yeniden_deneme_vadesi_gecmis_bayat_cevrimdisi_stale_gosterir(): void
    {
        $state = app(LocalState::class);
        $state->put('device_token_set', '1');
        // Uygulama yeni açıldı: state.json kapanmadan önceki "çevrimdışı"yı taşıyor, yeniden deneme zamanı geçmiş.
        $state->writeFile([
            'phase' => 'offline',
            'last_attempt_at' => now()->subSeconds(60)->toIso8601String(),
            'next_attempt_at' => now()->subSeconds(90)->toIso8601String(),
        ]);

        $this->assertSame('stale', $state->summary()['phase']);
    }

    public function test_yeniden_deneme_bekleniyorken_cevrimdisi_kalir(): void
    {
        $state = app(LocalState::class);
        $state->put('device_token_set', '1');
        // Normal çevrimdışı geri çekilme: yeniden deneme GELECEKTE → gösterge "çevrimdışı" kalmalı.
        $state->writeFile([
            'phase' => 'offline',
            'last_attempt_at' => now()->subSeconds(5)->toIso8601String(),
            'next_attempt_at' => now()->addSeconds(15)->toIso8601String(),
        ]);

        $this->assertSame('offline', $state->summary()['phase']);
    }
}
