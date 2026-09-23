<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Api\ApiController;
use App\Models\Integration;
use App\Services\Attendance\DeviceDiagnostics;
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
        'whatsapp' => ['wwebjs' => 'WhatsApp QR (kalıcı oturum)', 'meta_cloud' => 'Meta Cloud API', 'generic_http' => 'Genel HTTP'],
        'sms' => ['netgsm' => 'NetGSM', 'mutlucell' => 'Mutlucell', 'vatansms' => 'VatanSMS', 'simulation' => 'Simülasyon (test)', 'generic_http' => 'Genel HTTP (NetGSM uyumlu)'],
        'email' => ['smtp' => 'SMTP', 'simulation' => 'Simülasyon (test)'],
        'payment' => ['iyzico' => 'iyzico', 'generic' => 'Diğer'],
        'optical' => ['generic' => 'Optik okuyucu'],
        'biometric' => ['generic' => 'Biyometrik cihaz'],
        'storage' => ['local' => 'Yerel disk', 'public' => 'Genel disk (public)', 's3' => 'S3 uyumlu'],
    ];

    /**
     * ALANLI FORM ŞEMASI — ekranda "Yapılandırma JSON" ham metin kutusu KALMASIN diye
     * her sağlayıcının alanları burada, tek yerde tanımlıdır. Ön yüz bu listeyi okur;
     * kendi listesini tutmaz (iki yerde iki ayrı gerçek olmaz).
     *
     * tur: metin | sayi | gizli | secim | anahtar(boolean)
     */
    private const FIELDS = [
        'wwebjs' => [
            ['ad' => 'base_url', 'etiket' => 'Bot adresi (URL)', 'tur' => 'metin', 'zorunlu' => true, 'ipucu' => 'Örn. http://sunucu-ip:3000'],
            ['ad' => 'api_key', 'etiket' => 'Bot jetonu (API Token)', 'tur' => 'gizli', 'zorunlu' => true],
            ['ad' => 'session_id', 'etiket' => 'Oturum adı', 'tur' => 'metin', 'varsayilan' => 'kurs', 'ipucu' => 'Her kurum için ayrı numara = ayrı oturum adı.'],
        ],
        'meta_cloud' => [
            ['ad' => 'phone_number_id', 'etiket' => 'Telefon numarası kimliği (Phone Number ID)', 'tur' => 'metin', 'zorunlu' => true],
            ['ad' => 'business_account_id', 'etiket' => 'İşletme hesabı kimliği (WABA ID)', 'tur' => 'metin', 'zorunlu' => true],
            ['ad' => 'access_token', 'etiket' => 'Erişim jetonu (Access Token)', 'tur' => 'gizli', 'zorunlu' => true],
            ['ad' => 'app_secret', 'etiket' => 'Uygulama sırrı (App Secret)', 'tur' => 'gizli'],
            ['ad' => 'verify_token', 'etiket' => 'Doğrulama jetonu (Verify Token)', 'tur' => 'gizli'],
        ],
        'generic_http' => [
            ['ad' => 'url', 'etiket' => 'Adres (URL)', 'tur' => 'metin', 'zorunlu' => true],
            ['ad' => 'method', 'etiket' => 'HTTP metodu', 'tur' => 'secim', 'varsayilan' => 'POST',
                'secenekler' => [['deger' => 'POST', 'etiket' => 'POST'], ['deger' => 'GET', 'etiket' => 'GET']]],
            ['ad' => 'headers', 'etiket' => 'Başlıklar', 'tur' => 'metin', 'ipucu' => 'JSON, ör. {"Authorization":"Bearer …"}'],
            ['ad' => 'body_template', 'etiket' => 'Gövde şablonu', 'tur' => 'metin', 'ipucu' => '{{to}} ve {{body}} yer tutucuları kullanılabilir.'],
            ['ad' => 'success_path', 'etiket' => 'Başarı alanı', 'tur' => 'metin', 'ipucu' => 'Yanıt JSON yolu (isteğe bağlı).'],
            ['ad' => 'message_id_path', 'etiket' => 'Mesaj kimliği alanı', 'tur' => 'metin', 'ipucu' => 'İsteğe bağlı.'],
        ],
        'smtp' => [
            ['ad' => 'host', 'etiket' => 'SMTP sunucusu', 'tur' => 'metin', 'zorunlu' => true],
            ['ad' => 'port', 'etiket' => 'Port', 'tur' => 'sayi', 'varsayilan' => 587],
            ['ad' => 'username', 'etiket' => 'Kullanıcı adı', 'tur' => 'metin'],
            ['ad' => 'password', 'etiket' => 'Şifre', 'tur' => 'gizli'],
            ['ad' => 'encryption', 'etiket' => 'Şifreleme', 'tur' => 'secim', 'varsayilan' => 'tls',
                'secenekler' => [['deger' => 'tls', 'etiket' => 'TLS'], ['deger' => 'ssl', 'etiket' => 'SSL'], ['deger' => '', 'etiket' => 'Yok']]],
            ['ad' => 'from_address', 'etiket' => 'Gönderen adresi', 'tur' => 'metin'],
            ['ad' => 'from_name', 'etiket' => 'Gönderen adı', 'tur' => 'metin'],
        ],
        's3' => [
            ['ad' => 'disk', 'etiket' => 'Disk adı', 'tur' => 'metin', 'ipucu' => 'filesystems.php içindeki disk adı.'],
            ['ad' => 'key', 'etiket' => 'Anahtar (Access Key)', 'tur' => 'gizli'],
            ['ad' => 'secret', 'etiket' => 'Sır (Secret Key)', 'tur' => 'gizli'],
            ['ad' => 'bucket', 'etiket' => 'Kova (Bucket)', 'tur' => 'metin'],
            ['ad' => 'region', 'etiket' => 'Bölge', 'tur' => 'metin'],
        ],
        'local' => [['ad' => 'disk', 'etiket' => 'Disk adı', 'tur' => 'metin', 'varsayilan' => 'local']],
        'public' => [['ad' => 'disk', 'etiket' => 'Disk adı', 'tur' => 'metin', 'varsayilan' => 'public']],
        'iyzico' => [
            ['ad' => 'api_key', 'etiket' => 'API anahtarı', 'tur' => 'gizli', 'zorunlu' => true],
            ['ad' => 'secret_key', 'etiket' => 'Gizli anahtar (Secret)', 'tur' => 'gizli', 'zorunlu' => true],
            ['ad' => 'base_url', 'etiket' => 'Ortam', 'tur' => 'secim', 'varsayilan' => 'https://api.iyzipay.com',
                'secenekler' => [
                    ['deger' => 'https://api.iyzipay.com', 'etiket' => 'Canlı'],
                    ['deger' => 'https://sandbox-api.iyzipay.com', 'etiket' => 'Test (sandbox)'],
                ]],
        ],
        'generic' => [
            ['ad' => 'api_key', 'etiket' => 'API anahtarı', 'tur' => 'gizli'],
            ['ad' => 'base_url', 'etiket' => 'Adres (URL)', 'tur' => 'metin'],
        ],
        // Optik okuyucu: ayarları burada DEĞİL, içe aktarma sihirbazında yapılır (alan eşleme
        // dosyadan dosyaya değişir). Burada yalnız varsayılan davranış tutulur.
        'optical_generic' => [
            ['ad' => 'default_delimiter', 'etiket' => 'Varsayılan ayraç', 'tur' => 'secim', 'varsayilan' => 'auto',
                'secenekler' => [
                    ['deger' => 'auto', 'etiket' => 'Otomatik algıla (önerilen)'],
                    ['deger' => ';', 'etiket' => 'Noktalı virgül ( ; )'],
                    ['deger' => ',', 'etiket' => 'Virgül ( , )'],
                    ['deger' => 'tab', 'etiket' => 'Sekme (TAB)'],
                ], 'ipucu' => 'Sihirbaz yine de dosyaya bakıp doğrusunu önerir.'],
            ['ad' => 'default_encoding', 'etiket' => 'Karakter kodlaması', 'tur' => 'secim', 'varsayilan' => 'auto',
                'secenekler' => [
                    ['deger' => 'auto', 'etiket' => 'Otomatik (önerilen)'],
                    ['deger' => 'utf-8', 'etiket' => 'UTF-8'],
                    ['deger' => 'windows-1254', 'etiket' => 'Windows-1254 (Türkçe)'],
                ]],
            ['ad' => 'match_by', 'etiket' => 'Öğrenci eşleme ölçütü', 'tur' => 'secim', 'varsayilan' => 'student_no',
                'secenekler' => [
                    ['deger' => 'student_no', 'etiket' => 'Öğrenci numarası (önerilen)'],
                    ['deger' => 'student_no_then_name', 'etiket' => 'Önce numara, bulunamazsa ad soyad'],
                ], 'ipucu' => 'Ada göre eşleme yanlış öğrenciye puan yazabilir; yalnız numara yoksa kullanın.'],
        ],
    ];

    /**
     * Kartın "Yapılandır" düğmesi nereye gider? Bazı entegrasyonların yeri bu sayfa DEĞİLDİR:
     * biyometrik cihazların tek kaynağı `devices` tablosudur (Yoklama › Cihazlar), optik
     * eşleme ise dosyaya bakan sihirbazdadır. Burada ikinci bir kayıt tutulmaz.
     */
    private const MANAGED_ELSEWHERE = [
        'biometric' => [
            'yol' => '/yoklama/cihazlar',
            'etiket' => 'Cihazları yönet',
            'aciklama' => 'Biyometrik terminaller tek yerde tutulur: Yoklama › Cihazlar. Oradan ağda cihaz arayabilir, IP ile ekleyebilir ve köprüyü yönetebilirsiniz.',
        ],
        'optical' => [
            'yol' => '/optik-okuma',
            'etiket' => 'Optik okuma sihirbazı',
            'aciklama' => 'Optik dosyanın kolon eşlemesi dosyadan dosyaya değişir; sihirbaz dosyayı okuyup kolonları tanır, önizler ve aktarır.',
        ],
        'sms' => ['yol' => '/ayarlar/mesaj-kanallari', 'etiket' => 'Mesaj kanalları', 'aciklama' => 'SMS sağlayıcı ayarları mesaj kanalları ekranındadır.'],
        'email' => ['yol' => '/ayarlar/mesaj-kanallari', 'etiket' => 'Mesaj kanalları', 'aciklama' => 'E-posta (SMTP) ayarları mesaj kanalları ekranındadır.'],
    ];

    public function __construct(
        private readonly IntegrationTester $tester,
        private readonly DeviceDiagnostics $diagnostics,
    ) {}

    public function index(): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();
        $existing = Integration::query()->where('branch_id', $branchId)->get()->keyBy('kind');

        $cards = collect(self::KINDS)->map(function (string $kind) use ($existing, $branchId) {
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
                // Her sağlayıcının ALANLI form şeması (ham JSON kutusuna gerek kalmasın).
                'fields' => $this->fieldsFor($kind),
                // Kart buradan yönetilmiyorsa nereye gidileceği.
                'managed_elsewhere' => self::MANAGED_ELSEWHERE[$kind] ?? null,
                // Biyometrik kart, cihaz kayıtlarını `devices` tablosundan okur (tek kaynak).
                'devices' => $kind === 'biometric' ? $this->biometricSummary($branchId) : null,
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

    /**
     * Sağlayıcı bazlı alan şeması. Optik/biyometrik için tür bazlı özel anahtar kullanılır,
     * çünkü ikisinin de sağlayıcı anahtarı 'generic'tir ve ödeme 'generic'iyle karışır.
     *
     * @return list<array<string, mixed>>
     */
    private function fieldsFor(string $kind): array
    {
        $fields = [];

        foreach (array_keys(self::PROVIDERS[$kind]) as $provider) {
            $key = $kind === 'optical' ? 'optical_generic' : $provider;
            $fields[$provider] = self::FIELDS[$key] ?? [];
        }

        return $fields;
    }

    /**
     * Biyometrik kartın gösterdiği cihazlar — TEK KAYNAK `devices` tablosudur.
     * Entegrasyon kaydında ayrı bir cihaz listesi TUTULMAZ; burada yalnız özet okunur,
     * böylece bu kart ile Yoklama › Cihazlar › Terminal Köprüsü aynı veriyi gösterir.
     */
    private function biometricSummary(int $branchId): array
    {
        $rows = $this->diagnostics->forBranch($branchId);
        $terminals = array_values(array_filter($rows, fn (array $r) => in_array($r['tur'], ['fingerprint', 'face'], true)));

        return [
            'toplam' => count($terminals),
            'calisan' => count(array_filter($terminals, fn (array $r) => $r['durum'] === 'ok')),
            'sorunlu' => count(array_filter($terminals, fn (array $r) => in_array($r['durum'], ['hata', 'kurulmadi'], true))),
            'bekleyen_kayit' => array_sum(array_column($terminals, 'bekleyen_kayit')),
            'liste' => array_map(fn (array $r) => [
                'id' => $r['id'], 'ad' => $r['ad'], 'durum' => $r['durum'], 'durum_metni' => $r['durum_metni'],
                'cozum' => $r['cozum'], 'protokol_etiketi' => $r['protokol_etiketi'], 'ip' => $r['ip'],
                'seri_no' => $r['seri_no'], 'son_olay' => $r['son_olay'], 'bekleyen_kayit' => $r['bekleyen_kayit'],
            ], array_slice($terminals, 0, 6)),
        ];
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
