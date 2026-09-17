<?php

namespace App\Services\Integrations;

use App\Models\Device;
use App\Models\Integration;
use App\Services\Messaging\ProviderFactory;
use App\Services\Messaging\TestResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Entegrasyon kartındaki "Bağlantıyı test et" butonu: gerçek bir istek/denetim yapar,
 * sonucu `Integration.status/last_error/last_checked_at` alanlarına yazar.
 */
class IntegrationTester
{
    public function __construct(private readonly ProviderFactory $providers) {}

    public function test(Integration $integration): TestResult
    {
        $result = match ($integration->kind) {
            'whatsapp', 'sms', 'email' => $this->providers->make($integration->kind, $integration->provider)->testConnection($integration->config()),
            'biometric' => $this->testBiometric($integration),
            'storage' => $this->testStorage($integration),
            'payment', 'optical' => TestResult::fail('Bu entegrasyon türü kendi modülünün ayarlar sayfasından yönetilir.'),
            default => TestResult::fail('Bilinmeyen entegrasyon türü.'),
        };

        $integration->forceFill([
            'status' => $result->success ? 'connected' : 'error',
            'last_error' => $result->success ? null : mb_substr($result->message, 0, 1000),
            'last_checked_at' => now(),
        ])->save();

        return $result;
    }

    private function testBiometric(Integration $integration): TestResult
    {
        $online = Device::query()->where('kind', 'biometric')->where('is_active', true)
            ->where('last_seen_at', '>=', now()->subMinutes(5))->count();

        if ($online === 0) {
            return TestResult::fail('Son 5 dakikada çevrim içi biyometrik cihaz bulunamadı.');
        }

        return TestResult::ok("{$online} biyometrik cihaz çevrim içi.");
    }

    private function testStorage(Integration $integration): TestResult
    {
        $config = $integration->config();
        $disk = $config['disk'] ?? 'public';

        try {
            $path = 'integration-tests/'.Str::random(20).'.txt';
            Storage::disk($disk)->put($path, 'erbaa-bilgi-egitim-test-'.now()->timestamp);
            $ok = Storage::disk($disk)->exists($path) && Storage::disk($disk)->get($path) !== null;
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            return TestResult::fail('Depolama testi başarısız: '.$e->getMessage());
        }

        return $ok ? TestResult::ok("Yaz/oku/sil testi başarılı ({$disk}).") : TestResult::fail('Yazılan dosya okunamadı.');
    }
}
