<?php

namespace App\Http\Controllers\Api\Notifications;

use App\Http\Controllers\Api\ApiController;
use App\Models\MessageTemplate;
use App\Models\NotificationBatch;
use App\Models\OutboundMessage;
use App\Services\Notifications\EventNotificationService;
use App\Support\BranchContext;
use App\Support\Notifications\NotificationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Bildirim omurgası uçları: olay kataloğu, olay bazlı ayarlar, kitle şablonları, ve
 * "taslak → önizleme (PDF) → onay → gönder" gönderim akışı.
 */
class NotificationController extends ApiController
{
    public function __construct(private EventNotificationService $service) {}

    // ----------------------------------------------------------------- Katalog

    public function catalog(): JsonResponse
    {
        $events = collect(NotificationCatalog::events())->map(fn ($e, $type) => [
            'event_type' => $type,
            'label' => $e['label'],
            'group' => $e['group'],
            'audiences' => array_map(fn ($a) => ['key' => $a, 'label' => NotificationCatalog::AUDIENCES[$a]], $e['audiences']),
            'variables' => $e['variables'],
        ])->values();

        return response()->json([
            'events' => $events,
            'audiences' => NotificationCatalog::AUDIENCES,
        ]);
    }

    // ----------------------------------------------------------------- Ayarlar

    public function settings(): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();
        $data = collect(NotificationCatalog::eventTypes())->map(function ($type) use ($branchId) {
            $s = $this->service->setting($branchId, $type);

            return [
                'event_type' => $type,
                'label' => NotificationCatalog::label($type),
                'group' => NotificationCatalog::event($type)['group'],
                'enabled' => $s->enabled,
                'require_approval' => $s->require_approval,
                'channels' => $s->channels ?? ['whatsapp'],
                'audiences' => $s->audiences ?? NotificationCatalog::audiencesFor($type),
                'available_audiences' => NotificationCatalog::audiencesFor($type),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function updateSetting(Request $request, string $eventType): JsonResponse
    {
        abort_if(! NotificationCatalog::event($eventType), 404, 'Bilinmeyen olay.');
        $data = $request->validate([
            'enabled' => ['boolean'],
            'require_approval' => ['boolean'],
            'channels' => ['array'],
            'channels.*' => ['string', 'in:whatsapp,sms,email'],
            'audiences' => ['array'],
            'audiences.*' => ['string', 'in:student,parent,teacher,admin'],
        ]);
        $branchId = app(BranchContext::class)->require();
        $setting = $this->service->setting($branchId, $eventType);
        $setting->update($data);

        return $this->ok('Ayar güncellendi.', ['data' => $setting]);
    }

    // ----------------------------------------------------------------- Şablonlar (olay×kitle)

    public function templates(Request $request): JsonResponse
    {
        $eventType = (string) $request->query('event_type', '');
        abort_if(! NotificationCatalog::event($eventType), 422, 'Geçerli bir olay seçilmelidir.');
        $branchId = app(BranchContext::class)->require();
        $channel = (string) $request->query('channel', 'whatsapp');

        $data = $this->service->templatesFor($branchId, $eventType, $channel)->map(fn (MessageTemplate $t) => [
            'id' => $t->id,
            'event_type' => $t->event_type,
            'audience' => $t->audience,
            'audience_label' => NotificationCatalog::AUDIENCES[$t->audience] ?? $t->audience,
            'channel' => $t->channel,
            'name' => $t->name,
            'subject' => $t->subject,
            'body' => $t->body,
            'is_active' => $t->is_active,
        ]);

        return response()->json([
            'data' => $data,
            'variables' => NotificationCatalog::event($eventType)['variables'],
        ]);
    }

    public function updateTemplate(Request $request, MessageTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:4000'],
            'is_active' => ['boolean'],
        ]);
        $template->update($data);

        return $this->ok('Şablon güncellendi.', ['data' => $template]);
    }

    public function previewTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'vars' => ['array'],
        ]);
        $rendered = preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            fn ($m) => (string) ($data['vars'][$m[1]] ?? '['.$m[1].']'), $data['body']);

        return response()->json(['body' => $rendered]);
    }

    // ----------------------------------------------------------------- Gönderimler (batch)

    public function index(Request $request): JsonResponse
    {
        $query = NotificationBatch::query()->latest();
        if ($request->filled('event_type')) {
            $query->where('event_type', $request->query('event_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $page = $query->paginate($this->perPage($request));
        $countsMap = $this->statusCounts($page->getCollection()->pluck('id')->all());

        return $this->paginated($page, fn (NotificationBatch $b) => $this->batchJson($b, false, $countsMap[$b->id] ?? null));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_type' => ['required', 'string'],
            'student_ids' => ['array'],
            'student_ids.*' => ['integer'],
            'class_group_id' => ['nullable', 'integer'],
            'audiences' => ['array'],
            'audiences.*' => ['string', 'in:student,parent,teacher,admin'],
            'teacher_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:200'],
            'vars' => ['array'],
            'channel' => ['nullable', 'string', 'in:whatsapp,sms,email'],
        ]);
        abort_if(! NotificationCatalog::event($data['event_type']), 422, 'Bilinmeyen bildirim olayı.');

        $batch = $this->service->buildDrafts($data['event_type'], $data, $request->user()?->id);

        return response()->json(['message' => 'Taslak oluşturuldu.', 'data' => $this->batchJson($batch, true)], 201);
    }

    public function show(NotificationBatch $batch): JsonResponse
    {
        return response()->json(['data' => $this->batchJson($batch, true)]);
    }

    public function pdf(NotificationBatch $batch)
    {
        $path = $this->service->renderPdf($batch);

        return response()->file($path, ['Content-Type' => 'application/pdf']);
    }

    public function approve(Request $request, NotificationBatch $batch): JsonResponse
    {
        $batch = $this->service->approve($batch, (int) $request->user()->id);

        return $this->ok('Bildirimler onaylandı ve gönderildi.', ['data' => $this->batchJson($batch, true)]);
    }

    public function cancel(NotificationBatch $batch): JsonResponse
    {
        abort_if($batch->status === 'sent', 422, 'Gönderilmiş bildirim iptal edilemez.');
        $batch->update(['status' => 'cancelled']);

        return $this->ok('Gönderim iptal edildi.', ['data' => $this->batchJson($batch)]);
    }

    public function destroy(NotificationBatch $batch): JsonResponse
    {
        abort_if($batch->status === 'sent', 422, 'Gönderilmiş bildirim silinemez.');
        if ($batch->pdf_path) {
            Storage::disk('local')->delete($batch->pdf_path);
        }
        $batch->messages()->delete();
        $batch->delete();

        return $this->ok('Taslak silindi.');
    }

    // ----------------------------------------------------------------- Yardımcı

    private function batchJson(NotificationBatch $batch, bool $withMessages = false, ?array $counts = null): array
    {
        $c = $counts ?? ($this->statusCounts([$batch->id])[$batch->id]);
        $reached = $c['sent'] + $c['delivered'] + $c['read'];
        $pending = $c['queued'] + $c['sending'];

        $out = [
            'id' => $batch->id,
            'event_type' => $batch->event_type,
            'event_label' => NotificationCatalog::label($batch->event_type),
            'title' => $batch->title,
            'status' => $batch->status,
            'status_label' => NotificationBatch::STATUSES[$batch->status] ?? $batch->status,
            'audiences' => $batch->audiences ?? [],
            'total' => $batch->total,
            'sent' => $batch->sent,
            // Canlı gönderim sayaçları (outbound_messages'tan)
            'counts' => $c,
            'reached' => $reached,            // sent + delivered + read
            'delivered_like' => $c['delivered'] + $c['read'],
            'failed' => $c['failed'],
            'pending' => $pending,            // queued + sending
            'simulation' => $c['simulation'],
            'live' => $pending > 0,           // hâlâ işleniyor mu (canlı yenileme için)
            'pdf_available' => true,
            'created_at' => $batch->created_at,
            'approved_at' => $batch->approved_at,
        ];

        if ($withMessages) {
            $grouped = $batch->messages()->orderBy('audience')->orderBy('student_id')->get()
                ->groupBy('audience')
                ->map(fn ($msgs, $aud) => [
                    'audience' => $aud,
                    'audience_label' => NotificationCatalog::AUDIENCES[$aud] ?? $aud,
                    'count' => $msgs->count(),
                    'messages' => $msgs->map(fn ($m) => [
                        'id' => $m->id,
                        'to' => $m->to,
                        'status' => $m->status,
                        'provider' => $m->provider,
                        'body' => $m->body,
                        'student_id' => $m->student_id,
                    ])->values(),
                ])->values();
            $out['groups'] = $grouped;
        }

        return $out;
    }

    /**
     * Toplu: verilen batch id'leri için outbound_messages durum sayaçları (tek sorgu, N+1 yok).
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, int>>
     */
    private function statusCounts(array $ids): array
    {
        $keys = ['queued', 'sending', 'sent', 'delivered', 'read', 'failed'];
        $map = [];
        foreach ($ids as $id) {
            $map[$id] = array_fill_keys($keys, 0) + ['simulation' => 0];
        }
        if (! $ids) {
            return $map;
        }

        foreach (OutboundMessage::query()->whereIn('batch_id', $ids)
            ->selectRaw('batch_id, status, count(*) as c')->groupBy('batch_id', 'status')->get() as $r) {
            if (isset($map[$r->batch_id]) && array_key_exists($r->status, $map[$r->batch_id])) {
                $map[$r->batch_id][$r->status] = (int) $r->c;
            }
        }
        foreach (OutboundMessage::query()->whereIn('batch_id', $ids)->where('provider', 'simulation')
            ->selectRaw('batch_id, count(*) as c')->groupBy('batch_id')->get() as $r) {
            if (isset($map[$r->batch_id])) {
                $map[$r->batch_id]['simulation'] = (int) $r->c;
            }
        }

        return $map;
    }
}
