<?php

namespace App\Http\Controllers\Api;

use App\Models\Guardian;
use App\Services\Guardians\GuardianAccountService;
use App\Support\Audit;
use App\Support\Sensitive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuardianController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $sensitive = $user->can('students.view_sensitive');
        $canFinance = $user->can('finance.view');

        // select() withCount'tan ÖNCE: sonra çağrılırsa withCount'un eklediği students_count kolonu silinir
        $query = Guardian::query()->select('guardians.*')->with(['students:id,full_name,status', 'user:id,is_active,last_login_at'])->withCount('students')
            ->addSelect([
                // Son iletişim: veliye giden son mesaj (SMS/WhatsApp/e-posta)
                'last_contact_at' => DB::table('outbound_messages')->selectRaw('MAX(created_at)')
                    ->where('recipient_type', 'guardian')->whereColumn('recipient_id', 'guardians.id'),
            ]);
        if ($canFinance) {
            $query->addSelect([
                'overdue_balance' => DB::table('installments as i')
                    ->join('guardian_student as gs', 'gs.student_id', '=', 'i.student_id')
                    ->whereColumn('gs.guardian_id', 'guardians.id')->where('i.status', 'overdue')
                    ->selectRaw('COALESCE(SUM(i.amount - i.paid_amount), 0)'),
                'open_balance' => DB::table('installments as i')
                    ->join('guardian_student as gs', 'gs.student_id', '=', 'i.student_id')
                    ->whereColumn('gs.guardian_id', 'guardians.id')->whereIn('i.status', ['pending', 'partial', 'overdue'])
                    ->selectRaw('COALESCE(SUM(i.amount - i.paid_amount), 0)'),
            ]);
        }

        if ($q = trim((string) $request->query('q'))) {
            $digits = preg_replace('/\D/', '', $q);
            $query->where(fn ($w) => $w->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["{$q}%"])->orWhere('last_name', 'like', "{$q}%")
                ->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('phone', 'like', "%{$digits}"))
                ->orWhereHas('students', fn ($s) => $s->where('full_name', 'like', "{$q}%")));
        }
        if ($request->boolean('overdue') && $canFinance) {
            $query->whereExists(fn ($s) => $s->from('installments as i')->join('guardian_student as gs', 'gs.student_id', '=', 'i.student_id')
                ->whereColumn('gs.guardian_id', 'guardians.id')->where('i.status', 'overdue'));
        }

        // Varsayılan anahtar izin listesindeki 'name' olmalı ('first_name' yazınca sort parametresi olmayan istek 500 veriyordu)
        $this->applySort($query, $request, ['name' => 'first_name', 'last_name' => 'last_name', 'created_at' => 'created_at'], 'name');

        if ($request->boolean('duplicates')) {
            $query->whereIn('guardians.id', array_keys($duplicates ??= app(\App\Services\Guardians\GuardianMergeService::class)->duplicateMap()) ?: [0]);
        }
        // Olası mükerrer: aynı telefon/WhatsApp numarasına sahip başka veli
        $duplicates ??= app(\App\Services\Guardians\GuardianMergeService::class)->duplicateMap();

        return $this->paginated($query->paginate($this->perPage($request)), fn (Guardian $g) => [
            'id' => $g->id,
            'duplicate_ids' => $duplicates[$g->id] ?? [],
            'name' => $g->full_name,
            'phone' => $sensitive ? $g->phone : Sensitive::maskPhone($g->phone),
            'email' => $g->email,
            'occupation' => $g->occupation,
            'students' => $g->students->map(fn ($s) => [
                'id' => $s->id, 'full_name' => $s->full_name, 'status' => $s->status,
                'relationship' => $s->pivot->relationship, 'is_primary' => (bool) $s->pivot->is_primary,
            ]),
            'students_count' => $g->students_count,
            'overdue_balance' => $canFinance ? (string) ($g->overdue_balance ?? '0') : null,
            'open_balance' => $canFinance ? (string) ($g->open_balance ?? '0') : null,
            'portal' => $g->user ? ['is_active' => (bool) $g->user->is_active, 'last_login_at' => $g->user->last_login_at?->toAtomString()] : null,
            'last_contact_at' => $g->last_contact_at ? \Carbon\CarbonImmutable::parse($g->last_contact_at)->toAtomString() : null,
        ], [
            'duplicate_groups' => count(array_unique(array_map(fn ($id, $others) => min(array_merge([$id], $others)), array_keys($duplicates), array_values($duplicates)))),
            'duplicate_guardians' => count($duplicates),
        ]);
    }

    /** Veli profili: çocukları, ödeme durumu, devamsızlık, sınav, rehberlik notları, mesaj geçmişi. */
    public function show(Request $request, Guardian $guardian): JsonResponse
    {
        $user = $request->user();
        $sensitive = $user->can('students.view_sensitive');
        $guardian->load(['students.currentClassGroups:id,name']);
        $ids = $guardian->students->pluck('id');

        $children = $guardian->students->map(function ($s) use ($user) {
            $absences = DB::table('attendances')->where('student_id', $s->id)->where('date', '>=', now()->subDays(30)->toDateString())
                ->selectRaw("SUM(status='absent') AS absent, SUM(status='late') AS late")->first();
            $lastExam = DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->where('r.student_id', $s->id)
                ->where('e.status', 'results_published')->orderByDesc('e.exam_date')->first(['e.name', 'e.exam_date', 'r.net', 'r.institution_rank']);
            $finance = $user->can('finance.view') ? DB::table('installments')->where('student_id', $s->id)->where('status', '!=', 'cancelled')
                ->selectRaw("SUM(amount - paid_amount) AS remaining, SUM(CASE WHEN status='overdue' THEN amount - paid_amount ELSE 0 END) AS overdue, MIN(CASE WHEN status IN ('pending','partial','overdue') THEN due_date END) AS next_due")->first() : null;

            return [
                'id' => $s->id, 'full_name' => $s->full_name, 'student_no' => $s->student_no, 'status' => $s->status,
                'relationship' => $s->pivot->relationship, 'is_primary' => (bool) $s->pivot->is_primary,
                'class_groups' => $s->currentClassGroups->pluck('name'),
                'absent_30' => (int) ($absences->absent ?? 0), 'late_30' => (int) ($absences->late ?? 0),
                'last_exam' => $lastExam, 'finance' => $finance,
            ];
        });

        $guidance = $user->can('guidance.view')
            ? DB::table('guidance_meetings as g')->join('students as s', 's.id', '=', 'g.student_id')->whereIn('g.student_id', $ids)
                ->whereNull('g.deleted_at')->whereIn('g.visibility', ['staff', 'guardian'])->orderByDesc('g.met_at')->limit(10)
                ->get(['g.id', 'g.met_at', 'g.kind', 'g.summary', 's.full_name'])
            : [];

        $messages = $user->can('messages.view')
            ? DB::table('outbound_messages')->where('recipient_type', 'guardian')->where('recipient_id', $guardian->id)->orderByDesc('id')->limit(30)
                ->get(['id', 'channel', 'template_key', 'body', 'status', 'created_at', 'read_at'])
            : [];

        $consents = DB::table('communication_consents')->where('consentable_type', 'guardian')->where('consentable_id', $guardian->id)->get(['channel', 'purpose', 'granted', 'recorded_at', 'source']);

        return response()->json([
            'guardian' => [
                'id' => $guardian->id, 'name' => $guardian->full_name, 'first_name' => $guardian->first_name, 'last_name' => $guardian->last_name,
                'phone' => $sensitive ? $guardian->phone : Sensitive::maskPhone($guardian->phone),
                'whatsapp_phone' => $sensitive ? $guardian->whatsapp_phone : Sensitive::maskPhone($guardian->whatsapp_phone),
                'email' => $guardian->email, 'occupation' => $guardian->occupation, 'address' => $guardian->address, 'notes' => $guardian->notes,
                'national_id_masked' => Sensitive::maskNationalId($guardian->national_id_last4),
                'has_portal_access' => $guardian->user_id !== null,
            ],
            'children' => $children,
            'guidance' => $guidance,
            'messages' => $messages,
            'consents' => $consents,
        ]);
    }

    public function update(Request $request, Guardian $guardian): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:20'], 'whatsapp_phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email'], 'occupation' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:2000'],
            'whatsapp_consent' => ['sometimes', 'boolean'],
            'marketing_consents' => ['sometimes', 'array'],
            'marketing_consents.sms' => ['nullable', 'boolean'],
            'marketing_consents.email' => ['nullable', 'boolean'],
            'marketing_consents.whatsapp' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($guardian, $data, $request) {
            $guardian->fill(collect($data)->except(['whatsapp_consent', 'marketing_consents'])->all())->save();
            if (! empty($data['marketing_consents'])) {
                app(\App\Services\Campaigns\ConsentService::class)->syncFromForm('guardian', $guardian->id, $data['marketing_consents'], $request->user()->id);
            }
            if (array_key_exists('whatsapp_consent', $data)) {
                DB::table('communication_consents')->updateOrInsert(
                    ['consentable_type' => 'guardian', 'consentable_id' => $guardian->id, 'channel' => 'whatsapp', 'purpose' => 'informational'],
                    ['granted' => $data['whatsapp_consent'], 'source' => 'Veli profili', 'recorded_at' => now(), 'recorded_by' => $request->user()->id],
                );
            }
            Audit::log('guardian.updated', "{$guardian->full_name} veli bilgilerini güncelledi.", $guardian, Audit::diff($guardian));

            // Portal hesabı: ad ve kullanıcı adı (telefon) eşitlenir; hesap yoksa (ör. telefon yeni girildi) açılır
            $accounts = app(GuardianAccountService::class);
            if ($guardian->wasChanged(['first_name', 'last_name'])) {
                $accounts->syncName($guardian);
            }
            $accounts->syncUsername($guardian);
        });

        return $this->ok('Veli bilgileri güncellendi.');
    }
}
