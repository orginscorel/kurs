<?php

namespace App\Http\Controllers\Api\Communication;

use App\Http\Controllers\Api\ApiController;
use App\Models\MessageTemplate;
use App\Services\Communication\CommunicationAudit;
use App\Support\Audit;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Mesaj şablonları CRUD ({{degisken}} önizlemeli). */
class TemplateController extends ApiController
{
    /** Şablon metinlerinde kullanılabilecek bilinen değişkenler (yardımcı panel + önizleme). */
    public const KNOWN_VARS = [
        'ogrenci_adi', 'veli_adi', 'saat', 'ders_saati', 'ders_adi', 'gec_dakika', 'vade_tarihi', 'tutar',
        'gecikme_gun', 'sinav_adi', 'toplam_net', 'kurum_sirasi', 'ders_netleri', 'ders_listesi', 'dakika',
        'derslik', 'tarih', 'aciklama', 'odev_adi', 'teslim_tarihi', 'baslik', 'metin',
        'eski_sinif', 'yeni_sinif',
    ];

    public function index(Request $request): JsonResponse
    {
        $branchId = app(BranchContext::class)->id();
        $items = MessageTemplate::query()
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->query('channel')))
            ->orderBy('key')->orderBy('channel')->get();

        return response()->json(['data' => $items, 'known_vars' => self::KNOWN_VARS]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['branch_id'] = app(BranchContext::class)->require();
        $template = MessageTemplate::query()->create($data);
        CommunicationAudit::log('communication.template.create', "\"{$template->name}\" mesaj şablonunu oluşturdu.", $template);

        return response()->json(['message' => 'Şablon oluşturuldu.', 'id' => $template->id], 201);
    }

    public function update(Request $request, MessageTemplate $template): JsonResponse
    {
        $data = $this->validated($request, $template);
        $template->update($data);
        CommunicationAudit::log('communication.template.update', "\"{$template->name}\" mesaj şablonunu güncelledi.", $template, Audit::diff($template));

        return $this->ok('Şablon güncellendi.');
    }

    public function destroy(MessageTemplate $template): JsonResponse
    {
        $template->delete();
        CommunicationAudit::log('communication.template.delete', "\"{$template->name}\" mesaj şablonunu sildi.", $template);

        return $this->ok('Şablon silindi.');
    }

    /** Örnek değerlerle canlı önizleme. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string'], 'vars' => ['nullable', 'array']]);
        $vars = $data['vars'] ?? array_fill_keys(self::KNOWN_VARS, '');
        $rendered = preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn ($m) => (string) ($vars[$m[1]] ?? "[{$m[1]}]"), $data['body']);

        return response()->json(['preview' => $rendered]);
    }

    private function validated(Request $request, ?MessageTemplate $template = null): array
    {
        return $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_.]+$/',
                Rule::unique('message_templates', 'key')->where('channel', $request->input('channel'))->ignore($template?->id)],
            'channel' => ['required', Rule::in(['whatsapp', 'sms', 'email', 'push'])],
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:4000'],
            'provider_template' => ['nullable', 'string', 'max:120'],
            'language' => ['nullable', 'string', 'max:10'],
            'is_active' => ['boolean'],
        ], [
            'key.regex' => 'Sistem anahtarı yalnız küçük harf, rakam, nokta ve alt çizgi içerebilir (ör. veli.devamsizlik).',
            'key.unique' => 'Bu kanalda aynı sistem anahtarıyla bir şablon zaten var.',
        ], [
            'key' => 'Sistem anahtarı', 'channel' => 'Kanal', 'name' => 'Şablon adı', 'subject' => 'E-posta konusu',
            'body' => 'Mesaj metni', 'provider_template' => 'Meta onaylı şablon adı',
        ]);
    }
}
