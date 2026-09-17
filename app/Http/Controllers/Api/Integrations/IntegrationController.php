<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Api\ApiController;
use App\Models\Integration;
use App\Services\Integrations\IntegrationTester;
use App\Services\Communication\CommunicationAudit;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Entegrasyon merkezi: WhatsApp, SMS, E-posta, Ödeme, Optik Okuyucu, Biyometrik Cihaz, Depolama. */
class IntegrationController extends ApiController
{
    public const KINDS = ['whatsapp', 'sms', 'email', 'payment', 'optical', 'biometric', 'storage'];

    private const PROVIDERS = [
        'whatsapp' => ['meta_cloud' => 'Meta Cloud API', 'generic_http' => 'Genel HTTP'],
        'sms' => ['netgsm' => 'NetGSM', 'mutlucell' => 'Mutlucell', 'vatansms' => 'VatanSMS', 'simulation' => 'Simülasyon (test)', 'generic_http' => 'Genel HTTP (NetGSM uyumlu)'],
        'email' => ['smtp' => 'SMTP', 'simulation' => 'Simülasyon (test)'],
        'payment' => ['iyzico' => 'iyzico', 'generic' => 'Diğer'],
        'optical' => ['generic' => 'Optik okuyucu'],
        'biometric' => ['generic' => 'Biyometrik cihaz'],
        'storage' => ['local' => 'Yerel disk', 'public' => 'Genel disk (public)', 's3' => 'S3 uyumlu'],
    ];

    public function __construct(private readonly IntegrationTester $tester) {}

    public function index(): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();
        $existing = Integration::query()->where('branch_id', $branchId)->get()->keyBy('kind');

        $cards = collect(self::KINDS)->map(function (string $kind) use ($existing) {
            $row = $existing->get($kind);

            return [
                'kind' => $kind,
                'providers' => self::PROVIDERS[$kind],
                'provider' => $row?->provider,
                'status' => $row?->status ?? 'disconnected',
                'is_enabled' => (bool) ($row?->is_enabled ?? false),
                'last_error' => $row?->last_error,
                'last_checked_at' => $row?->last_checked_at,
                'config' => $row ? $this->mask($row->config()) : [],
            ];
        });

        return response()->json(['data' => $cards]);
    }

    public function update(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(array_keys(self::PROVIDERS[$kind]))],
            'is_enabled' => ['boolean'],
            'config' => ['nullable', 'array'],
        ]);

        $branchId = app(BranchContext::class)->require();
        $integration = Integration::query()->firstOrNew(['branch_id' => $branchId, 'kind' => $kind]);
        $existingConfig = $integration->exists ? $integration->config() : [];

        // Boş bırakılan alanlar (ör. maskelenmiş sır değiştirilmediyse) mevcut değeri korur.
        $newConfig = array_filter($data['config'] ?? [], fn ($v) => $v !== '' && $v !== null);
        $merged = array_merge($existingConfig, $newConfig);

        $integration->fill([
            'branch_id' => $branchId, 'kind' => $kind, 'provider' => $data['provider'],
            'is_enabled' => $data['is_enabled'] ?? $integration->is_enabled ?? false,
            'status' => 'disconnected', 'last_error' => null,
        ]);
        $integration->setConfig($merged);
        $integration->save();

        CommunicationAudit::log('communication.integration.update', "\"{$kind}\" entegrasyonunu güncelledi.", $integration);

        return $this->ok('Entegrasyon kaydedildi. Bağlantıyı test edin.');
    }

    public function test(string $kind): JsonResponse
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        $branchId = app(BranchContext::class)->require();
        $integration = Integration::query()->where('branch_id', $branchId)->where('kind', $kind)->first();

        if (! $integration) {
            return response()->json(['success' => false, 'message' => 'Önce yapılandırma kaydedin.'], 422);
        }

        $result = $this->tester->test($integration);
        CommunicationAudit::log('communication.integration.test', "\"{$kind}\" entegrasyonunu test etti: ".($result->success ? 'başarılı.' : 'başarısız.'), $integration);

        return response()->json(['success' => $result->success, 'message' => $result->message]);
    }

    /** Sır alanlarını maskeler: yalnızca son 4 karakter görünür. */
    private function mask(array $config): array
    {
        $secretKeys = ['token', 'secret', 'password', 'key', 'app_secret', 'access_token', 'api_id'];

        return collect($config)->map(function ($value, $key) use ($secretKeys) {
            if (! is_string($value) || $value === '') {
                return $value;
            }
            $isSecret = collect($secretKeys)->contains(fn ($s) => str_contains(mb_strtolower($key), $s));
            if (! $isSecret) {
                return $value;
            }

            return mb_strlen($value) > 4 ? str_repeat('•', 8).mb_substr($value, -4) : str_repeat('•', mb_strlen($value));
        })->all();
    }
}
