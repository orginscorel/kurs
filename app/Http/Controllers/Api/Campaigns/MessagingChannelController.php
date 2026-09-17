<?php

namespace App\Http\Controllers\Api\Campaigns;

use App\Http\Controllers\Api\ApiController;
use App\Models\Integration;
use App\Services\Campaigns\CampaignChannels;
use App\Services\Campaigns\CampaignText;
use App\Services\Communication\CommunicationAudit;
use App\Services\Integrations\IntegrationTester;
use App\Services\Messaging\ProviderFactory;
use App\Services\Messaging\Providers\EmailProvider;
use App\Services\Messaging\Sms\SmsLength;
use App\Support\BranchContext;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ayarlar › Mesaj kanalları: SMS sağlayıcısı ve kuruma özel SMTP. Kimlik bilgileri şifreli saklanır
 * (Integration::setConfig → Crypt) ve yanıtlarda ASLA açık dönmez (yalnız "kayıtlı" + son 2 karakter).
 * Kaydetme bağlantıyı "bağlı değil" yapar; "Bağlantıyı test et" başarılıysa "bağlı" olur.
 */
class MessagingChannelController extends ApiController
{
    public const SMS_PROVIDERS = [
        'netgsm' => 'NetGSM',
        'mutlucell' => 'Mutlucell',
        'vatansms' => 'VatanSMS',
        'simulation' => 'Simülasyon (test — gerçek gönderim yok)',
        'generic_http' => 'Genel HTTP (yalnız tekli bildirim)',
    ];

    public const EMAIL_PROVIDERS = [
        'smtp' => 'SMTP (kurum e-posta sunucusu)',
        'simulation' => 'Simülasyon (test — gerçek gönderim yok)',
    ];

    /** Sır sayılan alanlar: yanıtta hiçbir zaman açık dönmez. */
    private const SECRETS = ['password', 'api_key', 'api_id', 'access_token', 'app_secret', 'token', 'secret'];

    private const SMS_FIELDS = ['username', 'password', 'api_id', 'api_key', 'header', 'encoding', 'iys_brand_code', 'iys_recipient_type',
        'ret_number', 'ret_code', 'mersis_no', 'opt_out_text', 'unit_price', 'rate_per_minute', 'fail_numbers'];

    private const EMAIL_FIELDS = ['host', 'port', 'username', 'password', 'encryption', 'from_address', 'from_name', 'reply_to', 'rate_per_minute'];

    public function __construct(
        private readonly IntegrationTester $tester,
        private readonly ProviderFactory $factory,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $cards = [];
        foreach (['whatsapp', 'sms', 'email'] as $kind) {
            $row = CampaignChannels::integration($kind);
            $cards[$kind] = [
                'kind' => $kind,
                'provider' => $row?->provider,
                'provider_label' => $row?->provider ? ($kind === 'sms' ? (self::SMS_PROVIDERS[$row->provider] ?? $row->provider) : ($kind === 'email' ? (self::EMAIL_PROVIDERS[$row->provider] ?? $row->provider) : ($row->provider === 'meta_cloud' ? 'Meta Cloud API' : 'Genel HTTP'))) : null,
                'status' => $row?->status ?? 'disconnected',
                'is_enabled' => (bool) ($row?->is_enabled ?? false),
                'connected' => CampaignChannels::connected($row),
                'last_error' => $row?->last_error,
                'last_checked_at' => $row?->last_checked_at,
                'config' => $row && $kind !== 'whatsapp' ? $this->publicConfig($row->config()) : [],
            ];
        }

        $sms = CampaignChannels::integration('sms');

        return response()->json([
            'data' => $cards,
            'sms_providers' => self::SMS_PROVIDERS,
            'email_providers' => self::EMAIL_PROVIDERS,
            'sms_ready' => CampaignChannels::smsReady($sms),
            'email_ready' => CampaignChannels::emailReady(CampaignChannels::integration('email')),
            'opt_out_preview' => CampaignText::smsOptOut($sms?->config() ?? []),
            'can' => [
                'sms' => $request->user()->canAny(['integrations.sms', 'integrations.manage']),
                'email' => $request->user()->canAny(['integrations.email', 'integrations.manage']),
                'whatsapp' => $request->user()->can('integrations.manage'),
            ],
        ]);
    }

    public function update(Request $request, string $kind): JsonResponse
    {
        $this->authorizeKind($request, $kind);

        $rules = $kind === 'sms' ? [
            'provider' => ['required', Rule::in(array_keys(self::SMS_PROVIDERS))],
            'config.username' => ['nullable', 'string', 'max:80'],
            'config.password' => ['nullable', 'string', 'max:200'],
            'config.api_id' => ['nullable', 'string', 'max:200'],
            'config.api_key' => ['nullable', 'string', 'max:300'],
            'config.header' => ['nullable', 'string', 'max:20'],
            'config.encoding' => ['nullable', Rule::in(SmsLength::MODES)],
            'config.iys_brand_code' => ['nullable', 'string', 'max:20'],
            'config.iys_recipient_type' => ['nullable', Rule::in(['BIREYSEL', 'TACIR'])],
            'config.ret_number' => ['nullable', 'string', 'max:20'],
            'config.ret_code' => ['nullable', 'string', 'max:20'],
            'config.mersis_no' => ['nullable', 'string', 'max:30'],
            'config.opt_out_text' => ['nullable', 'string', 'max:160'],
            'config.unit_price' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'config.rate_per_minute' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'config.fail_numbers' => ['nullable', 'string', 'max:500'],
        ] : [
            'provider' => ['required', Rule::in(array_keys(self::EMAIL_PROVIDERS))],
            'config.host' => ['nullable', 'string', 'max:190'],
            'config.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'config.username' => ['nullable', 'string', 'max:190'],
            'config.password' => ['nullable', 'string', 'max:300'],
            'config.encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'config.from_address' => ['nullable', 'email', 'max:190'],
            'config.from_name' => ['nullable', 'string', 'max:120'],
            'config.reply_to' => ['nullable', 'email', 'max:190'],
            'config.rate_per_minute' => ['nullable', 'integer', 'min:1', 'max:600'],
        ];
        $data = $request->validate($rules + [
            'is_enabled' => ['boolean'],
            'config' => ['nullable', 'array'],
            'clear' => ['nullable', 'array'],
            'clear.*' => ['string'],
        ]);

        $fields = $kind === 'sms' ? self::SMS_FIELDS : self::EMAIL_FIELDS;
        $branchId = app(BranchContext::class)->require();
        $integration = Integration::query()->firstOrNew(['branch_id' => $branchId, 'kind' => $kind]);
        $current = $integration->exists ? $integration->config() : [];

        // Sağlayıcı değiştiyse eski sağlayıcının kimlik bilgileri taşınmaz
        if ($integration->exists && $integration->provider !== $data['provider']) {
            foreach (self::SECRETS as $secret) {
                unset($current[$secret]);
            }
            unset($current['header']);
        }

        $incoming = array_intersect_key((array) ($data['config'] ?? []), array_flip($fields));
        foreach ($incoming as $key => $value) {
            $isSecret = in_array($key, self::SECRETS, true);
            if ($value === null || $value === '') {
                if (! $isSecret) {
                    unset($current[$key]); // açık alan boşaltıldı
                }

                continue; // sır alanı boş = mevcut değeri koru
            }
            $current[$key] = is_string($value) ? trim($value) : $value;
        }
        foreach ((array) ($data['clear'] ?? []) as $key) {
            unset($current[$key]);
        }

        $integration->fill([
            'branch_id' => $branchId, 'kind' => $kind, 'provider' => $data['provider'],
            'is_enabled' => (bool) ($data['is_enabled'] ?? $integration->is_enabled ?? false),
            'status' => 'disconnected', 'last_error' => null,
        ]);
        $integration->setConfig($current);
        $integration->save();

        CommunicationAudit::log('communication.integration.update',
            ($kind === 'sms' ? 'SMS' : 'E-posta').' kanal ayarlarını güncelledi ('.($kind === 'sms' ? self::SMS_PROVIDERS[$data['provider']] : self::EMAIL_PROVIDERS[$data['provider']]).').',
            $integration, ['fields' => array_keys($incoming)]); // değerler denetim kaydına yazılmaz

        return $this->ok('Ayarlar kaydedildi. Göndermeye başlamadan önce "Bağlantıyı test et" ile doğrulayın.');
    }

    public function test(Request $request, string $kind): JsonResponse
    {
        $this->authorizeKind($request, $kind);
        $integration = CampaignChannels::integration($kind);
        if (! $integration) {
            return response()->json(['success' => false, 'message' => 'Önce ayarları kaydedin.'], 422);
        }

        $result = $this->tester->test($integration);
        CommunicationAudit::log('communication.integration.test', ($kind === 'sms' ? 'SMS' : 'E-posta').' bağlantısını test etti: '.($result->success ? 'başarılı.' : 'başarısız.'), $integration);

        return response()->json([
            'success' => $result->success,
            'message' => $result->message,
            'balance' => $kind === 'sms' ? $result->details : null,
            'enabled' => (bool) $integration->is_enabled,
        ]);
    }

    /** Bağlantıyı kapat: kanal "bağlı değil" olur, kimlik bilgileri silinmez. */
    public function disable(Request $request, string $kind): JsonResponse
    {
        $this->authorizeKind($request, $kind);
        $integration = CampaignChannels::integration($kind);
        if ($integration) {
            $integration->forceFill(['is_enabled' => false, 'status' => 'disconnected'])->save();
            CommunicationAudit::log('communication.integration.disable', ($kind === 'sms' ? 'SMS' : 'E-posta').' kanalını kapattı.', $integration);
        }

        return $this->ok('Kanal kapatıldı. Toplu gönderimler bu kanaldan yapılamaz.');
    }

    public function balance(Request $request): JsonResponse
    {
        $this->authorizeKind($request, 'sms');
        $integration = CampaignChannels::integration('sms');
        $gateway = $integration ? $this->factory->smsGateway($integration->provider) : null;
        if (! $gateway) {
            return response()->json(['success' => false, 'message' => 'Bakiye sorgusu için NetGSM, Mutlucell, VatanSMS ya da Simülasyon seçilmeli.'], 422);
        }

        $balance = $gateway->balance($integration->config());

        return response()->json(['success' => $balance->success, 'message' => $balance->summary()] + $balance->toArray());
    }

    public function originators(Request $request): JsonResponse
    {
        $this->authorizeKind($request, 'sms');
        $integration = CampaignChannels::integration('sms');
        $gateway = $integration ? $this->factory->smsGateway($integration->provider) : null;
        if (! $gateway) {
            return response()->json(['data' => [], 'message' => 'Önce sağlayıcıyı ve kimlik bilgilerini kaydedin.']);
        }

        $list = $gateway->originators($integration->config());

        return response()->json(['data' => $list, 'message' => $list ? null : 'Sağlayıcıdan başlık listesi alınamadı. Kimlik bilgilerini kontrol edin ya da başlığı elle yazın.']);
    }

    /** "Test e-postası gönder" — yalnız kullanıcı bir adres yazıp düğmeye bastığında. */
    public function testMessage(Request $request): JsonResponse
    {
        $this->authorizeKind($request, 'email');
        $to = $request->validate(['to' => ['required', 'email:rfc', 'max:190']])['to'];
        $integration = CampaignChannels::integration('email');
        if (! $integration) {
            return response()->json(['success' => false, 'message' => 'Önce SMTP ayarlarını kaydedin.'], 422);
        }

        $institution = (string) (Settings::get('institution.name') ?? 'Erbaa Bilgi Eğitim');
        $result = $integration->provider === 'simulation'
            ? \App\Services\Messaging\TestResult::ok('Simülasyon kipi: e-posta gönderilmedi (gerçek SMTP seçildiğinde gönderilir).')
            : app(EmailProvider::class)->sendTest($integration->config(), $to, $institution);

        CommunicationAudit::log('communication.integration.test_message', 'Test e-postası gönderdi: '.($result->success ? 'başarılı.' : 'başarısız.'), $integration);

        return response()->json(['success' => $result->success, 'message' => $result->message]);
    }

    private function authorizeKind(Request $request, string $kind): void
    {
        abort_unless(in_array($kind, ['sms', 'email'], true), 404);
        abort_unless($request->user()->canAny(['integrations.'.$kind, 'integrations.manage']), 403, 'Bu işlem için yetkiniz yok.');
    }

    /** Açık alanlar olduğu gibi; sır alanları yalnız "kayıtlı" bilgisi + son 2 karakter. */
    private function publicConfig(array $config): array
    {
        $out = [];
        foreach ($config as $key => $value) {
            if (in_array($key, self::SECRETS, true)) {
                $out[$key] = null;
                $out[$key.'_set'] = is_string($value) && $value !== '';
                $out[$key.'_hint'] = is_string($value) && mb_strlen($value) > 6 ? '••••'.mb_substr($value, -2) : ($value ? '••••' : null);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
