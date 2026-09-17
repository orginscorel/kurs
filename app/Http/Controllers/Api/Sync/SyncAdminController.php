<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Api\ApiController;
use App\Support\BranchContext;
use App\Sync\Models\SyncConflict;
use App\Sync\Models\SyncDevice;
use App\Sync\Server\ConflictService;
use App\Sync\Server\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Yönetim: Bağlı cihazlar, eşleştirme kodu, eşitleme çakışmaları.
 */
class SyncAdminController extends ApiController
{
    private function branchId(): int
    {
        return app(BranchContext::class)->require();
    }

    public function overview(): JsonResponse
    {
        $branch = $this->branchId();
        $since = now()->subDay();

        return response()->json([
            'node' => config('kurs.node'),
            'cursor' => (int) (DB::table('sync_changes')->max('id') ?? 0),
            'changes_24h' => DB::table('sync_changes')->where('branch_id', $branch)->where('created_at', '>=', $since)->count(),
            'device_changes_24h' => DB::table('sync_changes')->where('branch_id', $branch)->whereNotNull('origin_device_id')->where('created_at', '>=', $since)->count(),
            'devices_active' => SyncDevice::query()->where('branch_id', $branch)->where('status', 'active')->count(),
            'devices_online' => SyncDevice::query()->where('branch_id', $branch)->where('status', 'active')->where('last_seen_at', '>=', now()->subMinutes(5))->count(),
            'open_conflicts' => SyncConflict::query()->where('branch_id', $branch)->where('status', 'open')->count(),
            'finance_pending' => SyncConflict::query()->where('branch_id', $branch)->where('status', 'open')->where('kind', 'finance')->count(),
            'last_sweep_at' => DB::table('sync_table_state')->max('last_swept_at'),
            'tables_prepared' => DB::table('sync_table_state')->whereNotNull('baselined_at')->count(),
        ]);
    }

    public function devices(Request $request, DeviceService $service): JsonResponse
    {
        $q = SyncDevice::query()->where('branch_id', $this->branchId())->with('user:id,name');
        if ($request->query('durum') === 'iptal') {
            $q->where('status', 'revoked');
        } elseif ($request->query('durum') !== 'tumu') {
            $q->where('status', 'active');
        }
        $devices = $q->orderByDesc('last_seen_at')->orderByDesc('id')->get();

        return response()->json(['data' => $devices->map(fn (SyncDevice $d) => $service->present($d))->values()]);
    }

    public function revoke(Request $request, DeviceService $service, int $device): JsonResponse
    {
        $d = SyncDevice::query()->where('branch_id', $this->branchId())->findOrFail($device);
        if (! $d->isActive()) {
            return $this->ok('Bu cihaz zaten iptal edilmiş.');
        }
        $service->revoke($d, $request->user());

        return $this->ok("\"{$d->name}\" cihazının erişimi iptal edildi. Cihaz yeniden eşleştirilmeden eşitleme yapamaz.");
    }

    public function pairingCode(DeviceService $service): JsonResponse
    {
        return response()->json(['code' => $service->pairingCode($this->branchId()), 'server_url' => rtrim((string) config('app.url'), '/')]);
    }

    public function rotatePairingCode(DeviceService $service): JsonResponse
    {
        $code = $service->pairingCode($this->branchId(), true);
        \App\Support\Audit::log('sync.pairing_code_rotated', 'cihaz eşleştirme kurum kodunu yeniledi.');

        return response()->json(['code' => $code, 'message' => 'Kurum kodu yenilendi. Eşleşmiş cihazlar etkilenmez.']);
    }

    public function conflicts(Request $request): JsonResponse
    {
        $q = SyncConflict::query()->where('branch_id', $this->branchId())->with(['device:id,name,code', 'resolver:id,name']);
        $status = $request->query('durum', 'acik');
        match ($status) {
            'acik' => $q->where('status', 'open'),
            'cozuldu' => $q->whereIn('status', ['resolved', 'dismissed']),
            default => null,
        };
        if ($kind = $request->query('tur')) {
            $q->where('kind', $kind);
        }
        if ($search = trim((string) $request->query('q', ''))) {
            $q->where(fn ($w) => $w->where('row_label', 'like', "%$search%")->orWhere('field', 'like', "%$search%")->orWhere('table_name', 'like', "%$search%"));
        }
        $page = $q->orderByDesc('id')->paginate($this->perPage($request));

        return $this->paginated($page, fn (SyncConflict $c) => $this->present($c), [
            'counts' => SyncConflict::query()->where('branch_id', $this->branchId())->where('status', 'open')
                ->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->pluck('n', 'kind'),
        ]);
    }

    public function conflict(int $conflict): JsonResponse
    {
        $c = SyncConflict::query()->where('branch_id', $this->branchId())->with(['device:id,name,code', 'resolver:id,name'])->findOrFail($conflict);
        $history = $c->row_uuid ? DB::table('sync_changes')->where('table_name', $c->table_name)->where('row_uuid', $c->row_uuid)
            ->orderByDesc('id')->limit(20)->get(['id', 'op', 'fields', 'source', 'origin_device_id', 'user_id', 'created_at'])
            ->map(fn ($h) => [
                'id' => $h->id, 'op' => $h->op, 'source' => $h->source,
                'value' => $c->field ? (json_decode((string) $h->fields, true)[$c->field] ?? null) : null,
                'has_field' => $c->field ? array_key_exists($c->field, (array) json_decode((string) $h->fields, true)) : false,
                'device' => $h->origin_device_id ? SyncDevice::query()->whereKey($h->origin_device_id)->value('name') : null,
                'user' => $h->user_id ? DB::table('users')->where('id', $h->user_id)->value('name') : null,
                'at' => $h->created_at,
            ])->all() : [];

        return response()->json(['data' => $this->present($c) + ['history' => $history]]);
    }

    public function resolve(Request $request, ConflictService $service, int $conflict): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['keep', 'apply_other', 'reconciled', 'dismiss'])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $c = SyncConflict::query()->where('branch_id', $this->branchId())->findOrFail($conflict);
        $service->resolve($c, $data['action'], $data['note'] ?? null);

        return response()->json(['message' => 'Çakışma çözüldü.', 'data' => $this->present($c->fresh(['device', 'resolver']))]);
    }

    private function present(SyncConflict $c): array
    {
        return [
            'id' => $c->id,
            'kind' => $c->kind,
            'kind_label' => SyncConflict::KINDS[$c->kind] ?? $c->kind,
            'table' => $c->table_name,
            'table_label' => self::TABLE_LABELS[$c->table_name] ?? $c->table_name,
            'row_uuid' => $c->row_uuid,
            'row_label' => $c->row_label,
            'field' => $c->field,
            'field_label' => self::FIELD_LABELS[$c->field] ?? $c->field,
            'device_value' => $c->device_value,
            'server_value' => $c->server_value,
            'device_at' => $c->device_at?->toIso8601String(),
            'server_at' => $c->server_at?->toIso8601String(),
            'winner' => $c->winner,
            'status' => $c->status,
            'resolution' => $c->resolution,
            'note' => $c->note,
            'command_label' => $this->commandLabel($c),
            'device' => $c->device ? ['id' => $c->device->id, 'name' => $c->device->name, 'code' => $c->device->code] : null,
            'resolved_by' => $c->resolver?->name,
            'resolved_at' => $c->resolved_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    /** Finans mutabakatı / reddedilen komut: hangi işlem (Tahsilat, İade, POS yatışı…) */
    private function commandLabel(SyncConflict $c): ?string
    {
        if ($c->kind === 'finance') {
            return $c->device_value;
        }
        if ($c->kind === 'rejected' && $c->device_value && str_starts_with($c->device_value, '{')) {
            $name = json_decode($c->device_value, true)['command'] ?? null;

            return is_string($name) ? \App\Sync\Commands\CommandRegistry::label($name) : null;
        }

        return null;
    }

    private const TABLE_LABELS = [
        'students' => 'Öğrenci', 'guardians' => 'Veli', 'teachers' => 'Öğretmen', 'attendances' => 'Yoklama',
        'payments' => 'Tahsilat', 'enrollments' => 'Kayıt', 'leads' => 'Ön kayıt', 'guidance_meetings' => 'Rehberlik görüşmesi',
        'class_groups' => 'Sınıf', 'lesson_sessions' => 'Ders', 'homework' => 'Ödev', 'discipline_incidents' => 'Disiplin olayı',
        'refunds' => 'İade', 'finance_entries' => 'Gelir / gider', 'account_transfers' => 'Hesap aktarımı', 'pos_settlements' => 'POS yatışı',
        'promissory_notes' => 'Senet', 'collection_notes' => 'Tahsilat takibi', 'installments' => 'Taksit', 'documents' => 'Belge',
        'student_notes' => 'Öğrenci notu', 'tags' => 'Etiket', 'daily_presences' => 'Günlük giriş', 'attendance_events' => 'Giriş/çıkış olayı',
        'payment.collect' => 'Tahsilat', 'payment.void' => 'Tahsilat iptali', 'enrollment.create' => 'Kayıt',
        'refund.create' => 'İade', 'refund.void' => 'İade iptali', 'finance_entry.create' => 'Gelir / gider', 'finance_entry.void' => 'Gelir / gider iptali',
        'account_transfer.create' => 'Hesap aktarımı', 'account_transfer.void' => 'Aktarım iptali', 'pos_settlement.create' => 'POS yatışı',
        'pos_settlement.void' => 'POS yatışı iptali', 'promissory_note.prepare' => 'Senet', 'promissory_note.print' => 'Senet basımı',
        'collection_note.add' => 'Tahsilat takibi', 'collection_note.status' => 'Tahsilat takibi', 'collection_note.reminders' => 'Hatırlatma taslağı',
        'installment_plan.restructure' => 'Ödeme planı', 'installment_plan.adjust_price' => 'İndirim / burs',
    ];

    private const FIELD_LABELS = [
        'phone' => 'Telefon', 'whatsapp_phone' => 'WhatsApp', 'email' => 'E-posta', 'address' => 'Adres', 'status' => 'Durum',
        'first_name' => 'Ad', 'last_name' => 'Soyad', 'school_name' => 'Okul', 'notes' => 'Not', 'late_minutes' => 'Gecikme (dk)',
        'note' => 'Not', 'birth_date' => 'Doğum tarihi', 'school_grade' => 'Sınıf seviyesi', 'medical_notes' => 'Sağlık notu',
    ];
}
