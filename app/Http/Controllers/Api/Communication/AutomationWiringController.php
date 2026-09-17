<?php

namespace App\Http\Controllers\Api\Communication;

use App\Http\Controllers\Api\ApiController;
use App\Models\AutomationRule;
use App\Models\Webhook;
use App\Services\Automation\TriggerStats;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Otomasyon olay haritası: her tetikleyicinin kaynağı, bağlı kural sayısı, son 24 saatteki
 * tetiklenme (kural olmasa da sayılır) ve çalışma sonuçları; kurala bağlı olmayan sabit bağlantılar.
 */
class AutomationWiringController extends ApiController
{
    /** Kural gerektirmeyen, her zaman çalışan modüller arası bağlantılar. */
    private const FIXED_CHAINS = [
        ['event' => 'Tahsilat iptal edildi', 'effects' => ['Tahsilat yetkililerine uygulama bildirimi', 'Canlı akış', 'Webhook payment.voided']],
        ['event' => 'Yeni kayıt / ödeme planı', 'effects' => ['Muhasebeye uygulama bildirimi', 'Webhook enrollment.created']],
        ['event' => 'Öğrenci oluşturuldu / güncellendi', 'effects' => ['Webhook student.created / student.updated']],
        ['event' => 'Öğrencinin sınıfı değişti', 'effects' => ['Canlı akış (tekil değişim/takas)', 'Webhook student.class_changed']],
        ['event' => 'Öğrenci Ayrıldı / Donduruldu / Mezun', 'effects' => ['Planlı otomasyonlar iptal', 'Kuyruktaki mesajlar iptal', 'Açık görevler kapatılır', 'Taksit hatırlatmaları durur']],
        ['event' => 'CRM adayı kayda dönüştü', 'effects' => ['Aday sorumlusuna bildirim', 'Webhook lead.converted']],
        ['event' => 'Aday sonraki aksiyon zamanı', 'effects' => ['Sorumluya uygulama bildirimi (15 dk\'da bir kontrol)']],
        ['event' => 'Sınav sonucu yayımlandı', 'effects' => ['Webhook exam.completed + exam.result.created', 'Risk yeniden hesabı (kuyruk)', 'Net düşüşünde rehber öğretmene bildirim']],
        ['event' => 'Risk seviyesi yüksek\'e geçti', 'effects' => ['Rehber öğretmene "Görüşme planla" görevi + bildirim', 'Canlı akış', 'Webhook risk.high']],
        ['event' => 'Rehberlik görüşmesi kaydedildi', 'effects' => ['Risk yeniden hesabı', 'Açık "Görüşme planla" görevi tamamlanır']],
        ['event' => 'Ödev süresi doldu', 'effects' => ['Öğretmene "kontrol edilecek ödev" bildirimi', 'Webhook homework.missed']],
        ['event' => 'Gece gecikmiş taksit işaretleme', 'effects' => ['Webhook payment.overdue (taksit başına bir kez)']],
        ['event' => 'Her gün 08:00 / 20:00', 'effects' => ['Yöneticilere sabah özeti / gün sonu özeti bildirimi']],
    ];

    public function index(): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();
        $triggers = array_keys(AutomationRule::TRIGGERS);

        $rules = AutomationRule::query()->selectRaw('`trigger`, COUNT(*) AS total, SUM(is_active) AS active')->groupBy('trigger')->get()->keyBy('trigger');

        $runs = DB::table('automation_runs as ar')->join('automation_rules as r', 'r.id', '=', 'ar.automation_rule_id')
            ->where('r.branch_id', $branchId)->where('ar.created_at', '>=', now()->subDay())
            ->selectRaw('r.`trigger` AS tr, ar.status, COUNT(*) AS c')->groupBy('r.trigger', 'ar.status')->get()->groupBy('tr');

        $fired = TriggerStats::last24h($branchId, $triggers);

        $items = array_map(function (string $t) use ($rules, $runs, $fired) {
            $source = AutomationRule::TRIGGER_SOURCES[$t] ?? ['kind' => 'event', 'source' => null];
            $statuses = ($runs[$t] ?? collect())->pluck('c', 'status');

            return [
                'trigger' => $t, 'label' => AutomationRule::TRIGGERS[$t], 'kind' => $source['kind'], 'source' => $source['source'],
                'rules_total' => (int) ($rules[$t]->total ?? 0), 'rules_active' => (int) ($rules[$t]->active ?? 0),
                'fired_24h' => (int) ($fired[$t] ?? 0),
                'runs_24h' => ['done' => (int) ($statuses['done'] ?? 0), 'scheduled' => (int) ($statuses['scheduled'] ?? 0), 'skipped' => (int) ($statuses['skipped'] ?? 0), 'failed' => (int) ($statuses['failed'] ?? 0)],
            ];
        }, $triggers);

        $deliveries = DB::table('webhook_deliveries as d')->join('webhooks as w', 'w.id', '=', 'd.webhook_id')
            ->where('w.branch_id', $branchId)->where('d.created_at', '>=', now()->subDay())
            ->selectRaw('d.event, COUNT(*) AS c')->groupBy('d.event')->pluck('c', 'event');

        return response()->json([
            'data' => $items,
            'chains' => self::FIXED_CHAINS,
            'webhook_events' => array_map(fn ($e) => ['event' => $e, 'deliveries_24h' => (int) ($deliveries[$e] ?? 0)], Webhook::EVENTS),
        ]);
    }
}
