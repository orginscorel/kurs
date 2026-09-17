<?php

namespace App\Http\Controllers\Api\Communication;

use App\Http\Controllers\Api\ApiController;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Services\Automation\AutomationDescriber;
use App\Exceptions\BusinessRuleException;
use App\Services\Communication\CommunicationAudit;
use App\Services\Integrations\ChannelStatus;
use App\Support\Audit;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Otomasyon kuralları: tetikleyici → koşul → eylem, okunur cümle önizlemesi, çalışma geçmişi. */
class AutomationController extends ApiController
{
    public function triggers(): JsonResponse
    {
        return response()->json(['data' => AutomationRule::TRIGGERS]);
    }

    public function index(Request $request): JsonResponse
    {
        $items = AutomationRule::query()->withCount('runs')->orderBy('name')->get()->map(fn (AutomationRule $r) => $this->present($r));

        // Kanal durumu: ön yüz, bağlı olmayan kanalı kullanan kuralı açmaya çalışınca uyarı gösterir
        return response()->json(['data' => $items, 'channels' => ChannelStatus::all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['branch_id'] = app(BranchContext::class)->require();
        $data['created_by'] = $request->user()->id;
        $data['is_active'] = false; // yeni kurallar güvenli varsayılan olarak kapalı gelir

        $rule = AutomationRule::query()->create($data);
        CommunicationAudit::log('communication.automation.create', "\"{$rule->name}\" otomasyon kuralını oluşturdu.", $rule);

        return response()->json(['message' => 'Otomasyon kuralı oluşturuldu.', 'id' => $rule->id], 201);
    }

    public function update(Request $request, AutomationRule $automation): JsonResponse
    {
        $data = $this->validated($request);
        if ($automation->is_active) {
            // Açık kurala bağlı olmayan kanal eklenemez
            $this->assertChannelsConnected((clone $automation)->fill(['actions' => $data['actions']]));
        }
        $automation->update($data);
        CommunicationAudit::log('communication.automation.update', "\"{$automation->name}\" otomasyon kuralını güncelledi.", $automation, Audit::diff($automation));

        return $this->ok('Otomasyon kuralı güncellendi.');
    }

    public function toggle(AutomationRule $automation): JsonResponse
    {
        if (! $automation->is_active) {
            $this->assertChannelsConnected($automation);
        }
        $automation->update(['is_active' => ! $automation->is_active]);
        CommunicationAudit::log('communication.automation.toggle', "\"{$automation->name}\" otomasyon kuralını ".($automation->is_active ? 'etkinleştirdi.' : 'devre dışı bıraktı.'), $automation);

        return response()->json(['message' => 'Durum güncellendi.', 'is_active' => $automation->is_active]);
    }

    /** Önerilen veli bildirimleri (Entegrasyonlar ekranındaki tek tık seçeneği için önizleme). */
    public function recommended(): JsonResponse
    {
        $channels = ChannelStatus::all();

        return response()->json([
            'channels' => $channels,
            'data' => ChannelStatus::recommendedRules()->map(fn (AutomationRule $r) => [
                'id' => $r->id, 'name' => $r->name, 'trigger_label' => AutomationRule::TRIGGERS[$r->trigger] ?? $r->trigger,
                'is_active' => $r->is_active, 'missing_channels' => ChannelStatus::missingFor($r, $channels),
            ]),
        ]);
    }

    /** Önerilen veli bildirimlerini açar (yalnız kanalları bağlı olanları; zaten açık olanlara dokunmaz). */
    public function enableRecommended(): JsonResponse
    {
        $channels = ChannelStatus::all();
        if (! ($channels['whatsapp'] ?? false)) {
            throw new BusinessRuleException('Önce WhatsApp\'ı bağlayın: Ayarlar › Entegrasyonlar.', 'whatsapp_not_connected', ['channels' => ['whatsapp']], 422);
        }

        $enabled = [];
        foreach (ChannelStatus::recommendedRules() as $rule) {
            if ($rule->is_active || ChannelStatus::missingFor($rule, $channels) !== []) {
                continue;
            }
            $rule->update(['is_active' => true]);
            $enabled[] = $rule->name;
        }

        if ($enabled) {
            CommunicationAudit::log('communication.automation.enable_recommended', 'önerilen veli bildirimlerini açtı: '.implode(', ', $enabled).'.', null);
        }

        return response()->json([
            'message' => $enabled ? count($enabled).' veli bildirimi açıldı.' : 'Önerilen veli bildirimleri zaten açık.',
            'enabled' => $enabled,
        ]);
    }

    private function assertChannelsConnected(AutomationRule $rule): void
    {
        $missing = ChannelStatus::missingFor($rule);
        if ($missing === []) {
            return;
        }
        $labels = implode(', ', array_map(fn ($k) => ChannelStatus::LABELS[$k] ?? $k, $missing));
        $code = in_array('whatsapp', $missing, true) ? 'whatsapp_not_connected' : 'channel_not_connected';

        throw new BusinessRuleException("Bu kural {$labels} kullanıyor. Önce {$labels} bağlantısını kurun: Ayarlar › Entegrasyonlar.", $code, ['channels' => $missing], 422);
    }

    public function destroy(AutomationRule $automation): JsonResponse
    {
        $automation->delete();
        CommunicationAudit::log('communication.automation.delete', "\"{$automation->name}\" otomasyon kuralını sildi.", $automation);

        return $this->ok('Otomasyon kuralı silindi.');
    }

    public function runs(Request $request, AutomationRule $automation): JsonResponse
    {
        $query = AutomationRun::query()->where('automation_rule_id', $automation->id)->orderByDesc('id');

        return $this->paginated($query->paginate($this->perPage($request, 30)), fn (AutomationRun $r) => [
            'id' => $r->id, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id, 'status' => $r->status,
            'run_at' => $r->run_at, 'result' => $r->result,
        ]);
    }

    public function describe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trigger' => ['required', Rule::in(array_keys(AutomationRule::TRIGGERS))],
            'conditions' => ['nullable', 'array'],
            'delay_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'actions' => ['required', 'array', 'min:1'],
            'actions.*.type' => ['required', Rule::in(['whatsapp', 'sms', 'email', 'app'])],
            'actions.*.to' => ['required', Rule::in(['student', 'guardian', 'teacher', 'admin'])],
        ]);

        return response()->json(['description' => AutomationDescriber::describe($data['trigger'], $data['conditions'] ?? null, $data['actions'], $data['delay_minutes'] ?? 0)]);
    }

    private function present(AutomationRule $rule): array
    {
        return [
            'id' => $rule->id, 'name' => $rule->name, 'trigger' => $rule->trigger,
            'trigger_label' => AutomationRule::TRIGGERS[$rule->trigger] ?? $rule->trigger,
            'conditions' => $rule->conditions, 'actions' => $rule->actions, 'delay_minutes' => $rule->delay_minutes,
            'is_active' => $rule->is_active, 'run_count' => $rule->run_count, 'last_run_at' => $rule->last_run_at,
            'description' => AutomationDescriber::describe($rule->trigger, $rule->conditions, $rule->actions, $rule->delay_minutes),
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'trigger' => ['required', Rule::in(array_keys(AutomationRule::TRIGGERS))],
            'conditions' => ['nullable', 'array'],
            'delay_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'actions' => ['required', 'array', 'min:1'],
            'actions.*.type' => ['required', Rule::in(['whatsapp', 'sms', 'email', 'app'])],
            'actions.*.to' => ['required', Rule::in(['student', 'guardian', 'teacher', 'admin'])],
            'actions.*.template' => ['nullable', 'string', 'max:60'],
            'actions.*.attach_report_card' => ['nullable', 'boolean'],
        ], [
            'actions.required' => 'En az bir eylem ekleyin.',
            'actions.min' => 'En az bir eylem ekleyin.',
        ], [
            'name' => 'Kural adı', 'trigger' => 'Tetikleyici', 'delay_minutes' => 'Gönderim gecikmesi (dakika)',
            'actions' => 'Eylemler', 'actions.*.type' => 'Eylem kanalı', 'actions.*.to' => 'Eylem alıcısı',
            'actions.*.template' => 'Şablon anahtarı',
        ]);
    }
}
