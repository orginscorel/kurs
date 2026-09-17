<?php

namespace App\Http\Controllers\Api\Crm;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Lead;
use App\Models\Program;
use App\Models\User;
use App\Services\Crm\LeadService;
use App\Services\Finance\EnrollmentService;
use App\Services\Students\StudentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends ApiController
{
    /** Ön kayıt ekranının sade durumları → aday aşamaları */
    /** Sonuçlanan ön kayıtlar; takipteki kayıtlar arama planına göre "aranacak" / "arandı" diye ayrılır. */
    private const DURUMLAR = [
        'kayit' => ['won'],
        'olmadi' => ['lost'],
    ];

    /** Aranacak: planlı arama var ya da hiç aranmamış. Arandı: aranmış ve yeni arama planlanmamış. */
    private static function aramaPlani($query, string $durum): void
    {
        $query->whereNotIn('stage', ['won', 'lost']);
        if ($durum === 'aranacak') {
            $query->where(fn ($q) => $q->whereNotNull('next_action_at')->orWhere('stage', 'new'));
        } else {
            $query->whereNull('next_action_at')->where('stage', '!=', 'new');
        }
    }

    public function __construct(private readonly LeadService $leads) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request);
        $this->applySort($query, $request, [
            'full_name' => 'first_name', 'created_at' => 'created_at', 'next_action_at' => 'next_action_at',
            'last_contacted_at' => 'last_contacted_at', 'stage' => 'stage',
        ], '-created_at');

        return $this->paginated($query->paginate($this->perPage($request)), fn (Lead $l) => $this->row($l));
    }

    /** Kanban panosu: filtrelenmiş adaylar aşamaya göre gruplu (sayfalama yok, sürükle-bırak tüm sütunu görür). */
    public function board(Request $request): JsonResponse
    {
        $leads = $this->filtered($request, forBoard: true)->orderBy('stage_position')->limit(1000)->get();
        $grouped = $leads->groupBy('stage');

        $columns = collect(Lead::STAGES)->map(fn ($label, $stage) => [
            'stage' => $stage, 'label' => $label,
            'leads' => ($grouped[$stage] ?? collect())->map(fn (Lead $l) => $this->row($l))->values(),
        ])->values();

        return response()->json(['data' => $columns]);
    }

    /** Ön kayıt özeti: sade durum sayıları, geciken arama, bu ayın yeni ön kaydı ve kayda dönüşü */
    public function summary(): JsonResponse
    {
        $sayilar = Lead::query()->selectRaw('stage, count(*) as c')->groupBy('stage')->pluck('c', 'stage');
        $topla = fn (array $asamalar) => (int) collect($asamalar)->sum(fn ($a) => (int) ($sayilar[$a] ?? 0));
        $ayBasi = CarbonImmutable::now()->startOfMonth();
        $kazanilan = $topla(['won']);
        $kaybedilen = $topla(['lost']);

        return response()->json(['data' => [
            'durum' => ['aktif' => (int) $sayilar->sum() - $kazanilan - $kaybedilen]
                + ['aranacak' => tap(Lead::query(), fn ($q) => self::aramaPlani($q, 'aranacak'))->count(),
                    'arandi' => tap(Lead::query(), fn ($q) => self::aramaPlani($q, 'arandi'))->count()]
                + collect(self::DURUMLAR)->map(fn ($asamalar) => $topla($asamalar))->all(),
            'geciken' => Lead::query()->whereNotIn('stage', ['won', 'lost'])->whereNotNull('next_action_at')->where('next_action_at', '<', now())->count(),
            'bu_ay_yeni' => Lead::query()->where('created_at', '>=', $ayBasi)->count(),
            // Bu ay KAYDA DÖNEN adaylar (dönüşüm anına göre; aday kartında sonradan yapılan düzenleme sayımı değiştirmez)
            'bu_ay_kayit' => Lead::query()->where('stage', 'won')->where('converted_at', '>=', $ayBasi)->count(),
            'donusum' => ($kazanilan + $kaybedilen) > 0 ? round($kazanilan * 100 / ($kazanilan + $kaybedilen), 1) : null,
        ]]);
    }

    /**
     * Ön kayıt görüşme sonucu tek adımda: görüşme notu + durum + sonraki arama tarihi.
     * Kayıt olan öğrenci için "öğrenciye çevir" ayrı işlemdir (convert).
     */
    public function contact(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate([
            'sonuc' => ['required', Rule::in(['ulasildi', 'ulasilamadi', 'tekrar', 'olmadi'])],
            'kanal' => ['nullable', Rule::in(['call', 'whatsapp', 'meeting'])],
            'not' => ['nullable', 'string', 'max:2000'],
            'sonraki_arama' => ['nullable', 'date'],
            'neden' => ['nullable', 'required_if:sonuc,olmadi', 'string', 'max:300'],
        ]);
        if ($lead->student_id) {
            throw new BusinessRuleException('Bu ön kayıt öğrenci kaydına çevrilmiş; görüşme eklenemez.', 'lead_already_converted');
        }

        $etiket = ['ulasildi' => 'Arandı', 'ulasilamadi' => 'Ulaşılamadı', 'tekrar' => 'Aranacak', 'olmadi' => 'Kaydolmayacak'][$data['sonuc']];
        $not = trim((string) ($data['not'] ?? ''));
        $hedef = match ($data['sonuc']) {
            'ulasildi' => in_array($lead->stage, ['new', 'call_again', 'lost'], true) ? 'called' : $lead->stage,
            'ulasilamadi', 'tekrar' => 'call_again',
            'olmadi' => 'lost',
        };

        DB::transaction(function () use ($lead, $data, $etiket, $not, $hedef) {
            $this->leads->addActivity($lead, $data['kanal'] ?? 'call', $etiket.($not !== '' ? ' — '.$not : ''), ['sonuc' => $data['sonuc']]);
            if ($hedef !== $lead->stage) {
                $this->leads->move($lead, $hedef, [$lead->id], $hedef === 'lost' ? $data['neden'] : null);
            }
            $kapandi = $hedef === 'lost' || empty($data['sonraki_arama']);
            $lead->refresh()->forceFill([
                'next_action_at' => $kapandi ? null : $data['sonraki_arama'],
                'next_action' => $kapandi ? null : 'Tekrar ara',
            ])->save();
        });

        return $this->ok('Görüşme kaydedildi.');
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'stages' => Lead::STAGES,
            'sources' => Lead::SOURCES,
            'programs' => Program::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'owners' => User::query()->where('is_active', true)->whereIn('user_type', ['staff', 'teacher'])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Lead $lead): JsonResponse
    {
        $lead->load(['owner:id,name', 'program:id,name', 'student:id,full_name,student_no', 'activities.user:id,name', 'tasks' => fn ($q) => $q->orderByDesc('due_at')]);

        return response()->json(['data' => [
            ...$this->row($lead),
            'guardian_name' => $lead->guardian_name, 'guardian_phone' => $lead->guardian_phone, 'email' => $lead->email,
            'school_name' => $lead->school_name, 'school_grade' => $lead->school_grade, 'source_detail' => $lead->source_detail,
            'lost_reason' => $lead->lost_reason, 'offered_price' => $lead->offered_price,
            'activities' => $lead->activities->map(fn ($a) => [
                'id' => $a->id, 'kind' => $a->kind, 'body' => $a->body, 'meta' => $a->meta,
                'user' => $a->user?->name, 'created_at' => $a->created_at,
            ]),
            'tasks' => $lead->tasks->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'due_at' => $t->due_at, 'completed_at' => $t->completed_at]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $lead = $this->leads->create($this->validated($request));

        return response()->json(['message' => 'Ön kayıt eklendi.', 'id' => $lead->id, 'data' => $this->row($lead)], 201);
    }

    public function update(Request $request, Lead $lead): JsonResponse
    {
        $this->leads->update($lead, $this->validated($request));

        return $this->ok('Ön kayıt güncellendi.');
    }

    public function move(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in(array_keys(Lead::STAGES))],
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer'],
            'lost_reason' => ['nullable', 'string', 'max:300'],
        ]);

        $this->leads->move($lead, $data['stage'], $data['ordered_ids'], $data['lost_reason'] ?? null);

        return $this->ok('Aday aşaması güncellendi.');
    }

    public function storeActivity(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['call', 'meeting', 'whatsapp', 'note', 'offer'])],
            'body' => ['nullable', 'string', 'max:2000'],
            'meta' => ['nullable', 'array'],
        ]);

        $activity = $this->leads->addActivity($lead, $data['kind'], $data['body'] ?? null, $data['meta'] ?? null);

        return response()->json(['message' => 'Kayıt eklendi.', 'data' => $activity->load('user:id,name')], 201);
    }

    public function convert(Request $request, Lead $lead, StudentService $students, EnrollmentService $enrollments): JsonResponse
    {
        $data = $request->validate([
            'student' => ['required', 'array'],
            'student.first_name' => ['required', 'string', 'max:80'],
            'student.last_name' => ['required', 'string', 'max:80'],
            'student.national_id' => ['nullable', 'digits:11'],
            'student.birth_date' => ['nullable', 'date', 'before:today'],
            'student.gender' => ['nullable', Rule::in(['female', 'male', 'other'])],
            'student.school_name' => ['nullable', 'string', 'max:160'],
            'student.school_grade' => ['nullable', 'string', 'max:20'],
            'student.field' => ['nullable', Rule::in(['SAY', 'EA', 'SOZ', 'DIL', 'TYT', 'LGS'])],
            'student.phone' => ['nullable', 'string', 'max:20'],
            'student.email' => ['nullable', 'email', 'max:190'],
            'student.registered_on' => ['nullable', 'date'],
            'student.guardians' => ['required', 'array', 'min:1'],
            'student.guardians.*.first_name' => ['required', 'string', 'max:80'],
            'student.guardians.*.last_name' => ['required', 'string', 'max:80'],
            'student.guardians.*.phone' => ['required', 'string', 'max:20'],
            'student.guardians.*.relationship' => ['nullable', Rule::in(['mother', 'father', 'guardian', 'other', 'parent'])],
            'student.guardians.*.is_primary' => ['boolean'],
            'enrollment' => ['nullable', 'array'],
            'enrollment.academic_term_id' => ['required_with:enrollment', 'integer'],
            'enrollment.program_id' => ['required_with:enrollment', 'integer'],
            'enrollment.education_package_id' => ['nullable', 'integer'],
            'enrollment.class_group_id' => ['nullable', 'integer'],
            'enrollment.list_price' => ['required_with:enrollment', 'numeric', 'min:0'],
            'enrollment.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'enrollment.scholarship_amount' => ['nullable', 'numeric', 'min:0'],
            'enrollment.enrolled_on' => ['required_with:enrollment', 'date'],
            'enrollment.down_payment' => ['nullable', 'numeric', 'min:0'],
            'enrollment.installment_count' => ['required_with:enrollment', 'integer', 'min:0', 'max:36'],
            'enrollment.first_due_date' => ['required_with:enrollment', 'date'],
        ]);

        abort_unless($request->user()->can('students.create'), 403, 'Öğrenci oluşturma yetkiniz yok.');
        if (! empty($data['enrollment'])) {
            abort_unless($request->user()->can('enrollments.create'), 403, 'Kayıt oluşturma yetkiniz yok.');
        }

        $student = $this->leads->convert($lead, $data['student'], $data['enrollment'] ?? null, $students, $enrollments);

        return response()->json(['message' => "{$lead->full_name} öğrenciye dönüştürüldü.", 'student_id' => $student->id], 201);
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $this->leads->delete($lead);

        return $this->ok('Aday kaydı silindi.');
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->orderBy('stage')->orderBy('first_name')->orderBy('last_name');

        return response()->streamDownload(function () use ($query) {
            $writer = new Writer();
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(['Ad Soyad', 'Telefon', 'Veli', 'Veli Telefon', 'Kaynak', 'Aşama', 'İlgilendiği Program', 'Sorumlu', 'Son İletişim', 'Sonraki Aksiyon', 'Sonraki Aksiyon Tarihi', 'Teklif Fiyatı', 'Oluşturulma']));
            $query->chunk(500, function ($chunk) use ($writer) {
                foreach ($chunk as $l) {
                    $writer->addRow(Row::fromValues([
                        $l->full_name, $l->phone, $l->guardian_name ?? '', $l->guardian_phone ?? '', Lead::SOURCES[$l->source] ?? $l->source,
                        Lead::STAGES[$l->stage] ?? $l->stage, $l->program?->name ?? '', $l->owner?->name ?? '', $l->last_contacted_at?->format('d.m.Y H:i') ?? '',
                        $l->next_action ?? '', $l->next_action_at?->format('d.m.Y H:i') ?? '', $l->offered_price !== null ? (float) $l->offered_price : '',
                        $l->created_at->format('d.m.Y'),
                    ]));
                }
            });
            $writer->close();
        }, 'adaylar-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    // ------------------------------------------------------------------ yardımcılar

    private function filtered(Request $request, bool $forBoard = false): Builder
    {
        $query = Lead::query()->with(['owner:id,name', 'program:id,name'])
            // Görüşme sayısı: arama, yüz yüze ve WhatsApp kayıtları (not/aşama değişikliği sayılmaz)
            ->withCount(['activities as contact_count' => fn ($a) => $a->whereIn('kind', ['call', 'meeting', 'whatsapp'])]);

        $q = trim((string) $request->query('q'));
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q);
            $query->where(function (Builder $w) use ($q, $digits) {
                // leads tablosunda full_name kolonu yok: ad + soyad birleşiminde ara
                $w->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ['%'.$q.'%']);
                if (strlen($digits) >= 3) {
                    $w->orWhere('phone', 'like', '%'.$digits.'%');
                }
            });
        }
        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }
        if ($owner = $request->integer('owner_id')) {
            $query->where('owner_id', $owner);
        }
        if ($program = $request->integer('program_id')) {
            $query->where('interested_program_id', $program);
        }
        if (! $forBoard && ($stage = $request->query('stage'))) {
            $query->where('stage', $stage);
        }
        if (! $forBoard && ($durum = $request->query('durum'))) {
            if ($durum === 'aktif') {
                $query->whereNotIn('stage', ['won', 'lost']);
            } elseif ($durum === 'aranacak' || $durum === 'arandi') {
                self::aramaPlani($query, $durum);
            } elseif (isset(self::DURUMLAR[$durum])) {
                $query->whereIn('stage', self::DURUMLAR[$durum]);
            }
        }
        if ($request->boolean('geciken')) {
            $query->whereNotIn('stage', ['won', 'lost'])->whereNotNull('next_action_at')->where('next_action_at', '<', now());
        }
        if ($request->boolean('due_today')) {
            $query->whereNotIn('stage', ['won', 'lost'])->whereNotNull('next_action_at')->where('next_action_at', '<=', CarbonImmutable::today()->endOfDay());
        }
        $this->applyDateRange($query, $request, 'created_at');

        return $query;
    }

    private function row(Lead $l): array
    {
        $overdue = $l->next_action_at && $l->next_action_at->isPast() && ! in_array($l->stage, ['won', 'lost'], true);

        return [
            'id' => $l->id,
            'full_name' => $l->full_name,
            'first_name' => $l->first_name,
            'last_name' => $l->last_name,
            'phone' => $l->phone,
            'stage' => $l->stage,
            'stage_label' => Lead::STAGES[$l->stage] ?? $l->stage,
            'stage_position' => $l->stage_position,
            'source' => $l->source,
            'source_label' => Lead::SOURCES[$l->source] ?? $l->source,
            'program' => $l->relationLoaded('program') ? $l->program?->only(['id', 'name']) : null,
            'owner' => $l->relationLoaded('owner') ? $l->owner?->only(['id', 'name']) : null,
            'next_action' => $l->next_action,
            'next_action_at' => $l->next_action_at,
            'next_action_overdue' => $overdue,
            'last_contacted_at' => $l->last_contacted_at,
            'offered_price' => $l->offered_price !== null ? (string) $l->offered_price : null,
            'student_id' => $l->student_id,
            'created_at' => $l->created_at,
            'guardian_name' => $l->guardian_name,
            'guardian_phone' => $l->guardian_phone,
            'school_name' => $l->school_name,
            'school_grade' => $l->school_grade,
            'lost_reason' => $l->lost_reason,
            'contact_count' => isset($l->contact_count) ? (int) $l->contact_count : null,
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            // Öğrencinin telefonu olmayabilir: öğrenci ya da veli telefonundan en az biri yeterli
            'phone' => ['nullable', 'required_without:guardian_phone', 'string', 'max:20'],
            'guardian_name' => ['nullable', 'string', 'max:160'],
            'guardian_phone' => ['nullable', 'required_without:phone', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'],
            'school_name' => ['nullable', 'string', 'max:160'],
            'school_grade' => ['nullable', 'string', 'max:20'],
            'interested_program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')],
            'source' => ['required', Rule::in(array_keys(Lead::SOURCES))],
            'source_detail' => ['nullable', 'string', 'max:160'],
            'offered_price' => ['nullable', 'numeric', 'min:0'],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'next_action' => ['nullable', 'string', 'max:200'],
            'next_action_at' => ['nullable', 'date'],
        ], [
            'phone.required_without' => 'Öğrencinin ya da velinin telefonunu yazın.',
            'guardian_phone.required_without' => 'Öğrencinin ya da velinin telefonunu yazın.',
            'source.required' => 'Nereden duyduğunu seçin.',
        ]);
        $data['phone'] = (string) ($data['phone'] ?? '');

        return $data;
    }
}
