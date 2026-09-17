<?php

namespace App\Http\Controllers\Api\Campaigns;

use App\Http\Controllers\Api\ApiController;
use App\Models\CommunicationSuppression;
use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Lead;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Campaigns\ConsentService;
use App\Services\Communication\CommunicationAudit;
use App\Support\BranchContext;
use App\Support\Sensitive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * İleti izinleri (ticari elektronik ileti onayı — İYS) ve ret listesi.
 * Liste bir grup (öğrenci/veli/öğretmen/personel/aday) üzerinden sayfalanır; her satırda SMS ve e-posta onay durumu.
 */
class ConsentController extends ApiController
{
    private const GROUPS = [
        'guardians' => ['type' => 'guardian', 'model' => Guardian::class],
        'students' => ['type' => 'student', 'model' => Student::class],
        'teachers' => ['type' => 'teacher', 'model' => Teacher::class],
        'employees' => ['type' => 'employee', 'model' => Employee::class],
        'leads' => ['type' => 'lead', 'model' => Lead::class],
    ];

    public function __construct(private readonly ConsentService $consents) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group' => ['nullable', Rule::in(array_keys(self::GROUPS))],
            'state' => ['nullable', Rule::in(['granted', 'denied', 'none'])],
            'channel' => ['nullable', Rule::in(['sms', 'email'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $group = self::GROUPS[$data['group'] ?? 'guardians'];
        $type = $group['type'];
        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $group['model'];
        $table = $model->getTable();

        $query = $group['model']::query()->select("{$table}.*");
        if ($type === 'student') {
            $query->whereIn('status', ['active', 'enrolled', 'pending', 'frozen']);
        } elseif (in_array($type, ['teacher', 'employee'], true)) {
            $query->where('is_active', true);
        }

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $like = '%'.$term.'%';
            $query->where(function ($w) use ($like, $table, $type) {
                $type === 'student'
                    ? $w->where("{$table}.full_name", 'like', $like)
                    : $w->whereRaw("CONCAT({$table}.first_name, ' ', {$table}.last_name) LIKE ?", [$like]);
                $w->orWhere("{$table}.phone", 'like', $like)->orWhere("{$table}.email", 'like', $like);
            });
        }

        if (! empty($data['state'])) {
            $channel = $data['channel'] ?? 'sms';
            $sub = fn ($q) => $q->select(DB::raw(1))->from('communication_consents as cc')
                ->where('cc.consentable_type', $type)->whereColumn('cc.consentable_id', "{$table}.id")
                ->where('cc.channel', $channel)->where('cc.purpose', 'marketing');
            match ($data['state']) {
                'granted' => $query->whereExists(fn ($q) => $sub($q)->where('cc.granted', true)),
                'denied' => $query->whereExists(fn ($q) => $sub($q)->where('cc.granted', false)),
                default => $query->whereNotExists($sub),
            };
        }

        $type === 'student' ? $query->orderBy('full_name') : $query->orderBy('first_name')->orderBy('last_name');
        $page = $query->paginate($this->perPage($request, 30));

        $ids = collect($page->items())->pluck('id');
        $rows = DB::table('communication_consents')->where('consentable_type', $type)->whereIn('consentable_id', $ids)
            ->where('purpose', 'marketing')->get(['consentable_id', 'channel', 'granted', 'source', 'recorded_at']);
        $byPerson = [];
        foreach ($rows as $r) {
            $byPerson[$r->consentable_id][$r->channel] = ['granted' => (bool) $r->granted, 'source' => $r->source, 'recorded_at' => $r->recorded_at];
        }

        $branchId = app(BranchContext::class)->require();
        $phones = [];
        $emails = [];
        foreach ($page->items() as $p) {
            if ($ph = $this->phone($p, $type)) {
                $phones[] = $ph;
            }
            if ($p->email) {
                $emails[] = mb_strtolower($p->email);
            }
        }
        $suppressed = CommunicationSuppression::query()->where('branch_id', $branchId)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('channel', 'sms')->whereIn('address', $phones))
                ->orWhere(fn ($w) => $w->where('channel', 'email')->whereIn('address', $emails)))
            ->get(['channel', 'address'])->map(fn ($s) => $s->channel.':'.$s->address)->flip();

        $canSeeFull = $request->user()->can('students.view_sensitive');

        return $this->paginated($page, function ($p) use ($type, $byPerson, $suppressed, $canSeeFull) {
            $phone = $this->phone($p, $type);
            $email = $p->email ? mb_strtolower($p->email) : null;

            return [
                'type' => $type,
                'id' => $p->id,
                'name' => $type === 'student' ? $p->full_name : trim($p->first_name.' '.$p->last_name),
                'phone' => $phone ? ($canSeeFull ? $phone : Sensitive::maskPhone($phone)) : null,
                'email' => $email,
                'sms' => $byPerson[$p->id]['sms'] ?? null,
                'email_consent' => $byPerson[$p->id]['email'] ?? null,
                'sms_suppressed' => $phone ? isset($suppressed['sms:'.$phone]) : false,
                'email_suppressed' => $email ? isset($suppressed['email:'.$email]) : false,
            ];
        }, ['summary' => $this->summary($group)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.type' => ['required', Rule::in(ConsentService::TYPES)],
            'items.*.id' => ['required', 'integer'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(['sms', 'email'])],
            'granted' => ['required', 'boolean'],
            'source' => ['required', 'string', 'max:60'],
        ], ['source.required' => 'Onayın kaynağını yazın (ör. "Kayıt formu — ıslak imza").']);

        // Yalnız bu şubeye ait kişiler (şube kapsamlı modeller üzerinden doğrulanır)
        $valid = [];
        foreach (collect($data['items'])->groupBy('type') as $type => $items) {
            $class = collect(self::GROUPS)->firstWhere('type', $type)['model'];
            foreach ($class::query()->whereIn('id', $items->pluck('id'))->pluck('id') as $id) {
                $valid[] = ['type' => $type, 'id' => (int) $id];
            }
        }

        $count = $this->consents->record($valid, $data['channels'], $data['granted'], $data['source'], $request->user()->id);
        CommunicationAudit::log('communication.consent.record',
            count($valid).' kişi için ticari ileti '.($data['granted'] ? 'onayı kaydetti' : 'reddi kaydetti').' ('.implode(', ', array_map('strtoupper', $data['channels'])).'; kaynak: '.$data['source'].')',
            null, ['people' => array_slice($valid, 0, 200), 'channels' => $data['channels'], 'granted' => $data['granted']]);

        return $this->ok(count($valid).' kişinin izin kaydı güncellendi.', ['count' => $count]);
    }

    public function suppressions(Request $request): JsonResponse
    {
        $query = CommunicationSuppression::query()
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->query('channel')))
            ->when($request->filled('q'), fn ($q) => $q->where('address', 'like', '%'.$request->query('q').'%'))
            ->orderByDesc('id');
        $canSeeFull = $request->user()->can('students.view_sensitive');

        return $this->paginated($query->paginate($this->perPage($request, 30)), fn (CommunicationSuppression $s) => [
            'id' => $s->id,
            'channel' => $s->channel,
            'address' => $s->channel === 'sms' && ! $canSeeFull ? Sensitive::maskPhone($s->address) : $s->address,
            'reason' => $s->reason,
            'reason_label' => CommunicationSuppression::REASONS[$s->reason] ?? $s->reason,
            'source' => $s->source,
            'created_at' => $s->created_at,
        ]);
    }

    public function addSuppression(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['sms', 'email'])],
            'address' => ['required', 'string', 'max:160'],
            'reason' => ['nullable', Rule::in(['manual', 'ret', 'bounce'])],
            'source' => ['nullable', 'string', 'max:120'],
        ]);
        $address = CommunicationSuppression::normalize($data['channel'], $data['address']);
        $valid = $data['channel'] === 'email' ? filter_var($address, FILTER_VALIDATE_EMAIL) : preg_match('/^90\d{10}$/', $address);
        if (! $valid) {
            return response()->json(['message' => $data['channel'] === 'email' ? 'Geçerli bir e-posta adresi girin.' : 'Geçerli bir cep telefonu girin (05xx…).', 'error_code' => 'invalid_address'], 422);
        }

        $this->consents->optOut(app(BranchContext::class)->require(), $data['channel'], null, null, $address,
            $data['reason'] ?? 'manual', $data['source'] ?? 'Elle eklendi', $request->user()->id);
        CommunicationAudit::log('communication.suppression.add', 'Ret listesine '.($data['channel'] === 'sms' ? 'numara' : 'e-posta').' ekledi.', null, ['channel' => $data['channel']]);

        return $this->ok('Adres ret listesine eklendi; toplu gönderimlerde atlanacak.');
    }

    public function removeSuppression(CommunicationSuppression $suppression): JsonResponse
    {
        $suppression->delete();
        CommunicationAudit::log('communication.suppression.remove', 'Ret listesinden bir adresi çıkardı ('.$suppression->channel.').', null, ['channel' => $suppression->channel, 'reason' => $suppression->reason]);

        return $this->ok('Adres ret listesinden çıkarıldı. Ticari ileti için kişinin onayı ayrıca kayıtlı olmalı.');
    }

    private function phone(object $p, string $type): ?string
    {
        return match ($type) {
            'guardian' => $p->messagingPhone(),
            'student', 'teacher' => Sensitive::normalizePhone($p->whatsapp_phone ?: $p->phone),
            'lead' => Sensitive::normalizePhone($p->phone ?: $p->guardian_phone),
            default => Sensitive::normalizePhone($p->phone),
        };
    }

    /**
     * Gruptaki onaylı kişi sayısı — yalnız oturumdaki şubenin (ve listede görünen durumdaki) kişileri sayılır.
     *
     * @param  array{type:string, model:class-string}  $group
     * @return array{sms_granted:int, email_granted:int}
     */
    private function summary(array $group): array
    {
        $type = $group['type'];
        // Şube kapsamı modelin global kapsamından gelir (BelongsToBranch)
        $people = $group['model']::query()->select('id')->where('branch_id', app(BranchContext::class)->require());
        if ($type === 'student') {
            $people->whereIn('status', ['active', 'enrolled', 'pending', 'frozen']);
        } elseif (in_array($type, ['teacher', 'employee'], true)) {
            $people->where('is_active', true);
        }

        $rows = DB::table('communication_consents')->where('consentable_type', $type)->where('purpose', 'marketing')->where('granted', true)
            ->whereIn('consentable_id', $people)
            ->selectRaw('channel, count(*) c')->groupBy('channel')->pluck('c', 'channel');

        return ['sms_granted' => (int) ($rows['sms'] ?? 0), 'email_granted' => (int) ($rows['email'] ?? 0)];
    }
}
