<?php

namespace App\Http\Controllers\Api\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Concerns\IsoJson;
use App\Http\Middleware\EnsurePortalStudent;
use App\Models\PackageRequest;
use App\Models\Student;
use App\Support\Audit;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Öğrenci/veli portalı "Paketlerim": öğrencinin aktif kayıt paketleri, içerikleri, ödeme planı özeti ve
 * KOÇLUK durumu; ayrıca şubedeki uygun paketler ile koçluk add-on'u için TALEP oluşturma (online ödeme YOK).
 *
 * Her uç yalnız oturumdaki öğrencinin / velinin bağlı öğrencisinin verisidir (EnsurePortalStudent).
 * Talep hem öğrenci hem veli hesabından oluşturulabilir; önizlemede (personel) yazma zaten kapalıdır
 * (impersonation.readonly). Yönetici talebi "Paket talepleri" ekranında kesinleştirir.
 */
class PortalPackagesController extends ApiController
{
    use IsoJson;

    private const MAX_OPEN_REQUESTS = 5;

    private function student(Request $request): Student
    {
        return $request->attributes->get(EnsurePortalStudent::ATTRIBUTE);
    }

    private function isGuardian(Request $request): bool
    {
        return EnsurePortalStudent::isGuardianRequest($request);
    }

    public function index(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $coaching = $this->coachingStatus($student);

        $enrollments = DB::table('enrollments as e')
            ->leftJoin('programs as p', 'p.id', '=', 'e.program_id')
            ->leftJoin('academic_terms as at', 'at.id', '=', 'e.academic_term_id')
            ->leftJoin('education_packages as pk', 'pk.id', '=', 'e.education_package_id')
            ->where('e.student_id', $student->id)->whereNull('e.deleted_at')
            ->orderByDesc('e.enrolled_on')->orderByDesc('e.id')
            ->get([
                'e.id', 'e.enrollment_no', 'p.name as program', 'at.name as term', 'e.status', 'e.enrolled_on',
                'e.list_price', 'e.net_price', DB::raw('(e.discount_amount + e.scholarship_amount) AS discount'),
                'e.education_package_id', 'pk.name as package_name', 'pk.includes as package_includes',
                'pk.list_price as package_list_price', 'pk.default_installments as package_installments', 'pk.has_coaching as package_has_coaching',
            ]);

        $instByEnrollment = DB::table('installments')->where('student_id', $student->id)->where('status', '!=', 'cancelled')
            ->groupBy('enrollment_id')
            ->selectRaw("enrollment_id, COUNT(*) AS c, COALESCE(SUM(amount),0) AS total, COALESCE(SUM(paid_amount),0) AS paid,
                COALESCE(SUM(status='paid'),0) AS paid_count, COALESCE(SUM(status='overdue'),0) AS overdue_count")
            ->get()->keyBy('enrollment_id');

        $rows = $enrollments->map(function ($e) use ($instByEnrollment) {
            $inst = $instByEnrollment[$e->id] ?? null;

            return [
                'id' => (int) $e->id,
                'enrollment_no' => $e->enrollment_no,
                'program' => $e->program,
                'term' => $e->term,
                'status' => $e->status,
                'status_label' => $this->enrollmentLabels()[$e->status] ?? $e->status,
                'enrolled_on' => $e->enrolled_on,
                'list_price' => (string) $e->list_price,
                'net_price' => (string) $e->net_price,
                'discount' => (string) $e->discount,
                'package' => $e->education_package_id ? [
                    'id' => (int) $e->education_package_id,
                    'name' => $e->package_name,
                    'includes' => $e->package_includes,
                    'list_price' => $e->package_list_price !== null ? (string) $e->package_list_price : null,
                    'default_installments' => $e->package_installments !== null ? (int) $e->package_installments : null,
                    'has_coaching' => (bool) $e->package_has_coaching,
                ] : null,
                'payment' => [
                    'installment_count' => (int) ($inst->c ?? 0),
                    'paid_count' => (int) ($inst->paid_count ?? 0),
                    'overdue_count' => (int) ($inst->overdue_count ?? 0),
                    'total' => (string) ($inst->total ?? '0'),
                    'paid' => (string) ($inst->paid ?? '0'),
                    'remaining' => bcsub((string) ($inst->total ?? '0'), (string) ($inst->paid ?? '0'), 2),
                ],
            ];
        });

        $requestsEnabled = (bool) Settings::get('portal.package_requests_enabled', true, $student->branch_id);

        $available = DB::table('education_packages as pk')
            ->leftJoin('programs as p', 'p.id', '=', 'pk.program_id')
            ->leftJoin('academic_terms as at', 'at.id', '=', 'pk.academic_term_id')
            ->where('pk.branch_id', $student->branch_id)->where('pk.is_active', true)->whereNull('pk.deleted_at')
            ->orderBy('pk.name')
            ->get(['pk.id', 'pk.name', 'pk.includes', 'pk.list_price', 'pk.default_installments', 'pk.has_coaching', 'p.name as program', 'at.name as term'])
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => $p->name,
                'includes' => $p->includes,
                'list_price' => (string) $p->list_price,
                'default_installments' => (int) $p->default_installments,
                'has_coaching' => (bool) $p->has_coaching,
                'program' => $p->program,
                'term' => $p->term,
            ]);

        $requests = PackageRequest::query()->where('student_id', $student->id)
            ->with('package:id,name')->latest('id')->limit(30)->get()
            ->map(fn (PackageRequest $r) => $this->requestRow($r));

        return $this->isoJson([
            'coaching' => $coaching,
            'enrollments' => $rows,
            'available_packages' => $available,
            // Koçluk add-on'u: aktif koçluk yoksa talep edilebilir
            'coaching_addon_available' => ! $coaching['active'],
            'requests' => $requests,
            'requests_enabled' => $requestsEnabled,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $student = $this->student($request);
        if (! Settings::get('portal.package_requests_enabled', true, $student->branch_id)) {
            throw new BusinessRuleException('Kurum şu an portal üzerinden paket talebi almıyor. Lütfen kurumu arayın.', 'requests_disabled', [], 403);
        }

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(PackageRequest::KINDS))],
            'package_id' => ['nullable', 'integer', 'required_if:kind,package'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'kind.required' => 'Talep türü seçin.',
            'package_id.required_if' => 'Bir paket seçin.',
        ]);

        $kind = $data['kind'];
        $packageId = null;
        $packageName = null;

        if ($kind === 'package') {
            $package = DB::table('education_packages')->where('id', (int) $data['package_id'])
                ->where('branch_id', $student->branch_id)->where('is_active', true)->whereNull('deleted_at')
                ->first(['id', 'name']);
            if (! $package) {
                throw new BusinessRuleException('Seçilen paket bulunamadı ya da artık geçerli değil.', 'package_invalid', [], 422);
            }
            $packageId = (int) $package->id;
            $packageName = $package->name;
        } elseif ($this->coachingStatus($student)['active']) {
            throw new BusinessRuleException('Öğrencinin zaten aktif bir koçu var.', 'coaching_active', [], 422);
        }

        // Aynı türde (paket ise aynı paket için) bekleyen talep varsa tekrar oluşturulmaz
        $dupe = PackageRequest::query()->where('student_id', $student->id)->where('status', 'pending')
            ->where('kind', $kind)->when($kind === 'package', fn ($q) => $q->where('package_id', $packageId))->exists();
        if ($dupe) {
            throw new BusinessRuleException('Bu talep için zaten bekleyen bir başvurunuz var.', 'request_duplicate', [], 422);
        }

        $open = PackageRequest::query()->where('student_id', $student->id)->where('status', 'pending')->count();
        if ($open >= self::MAX_OPEN_REQUESTS) {
            throw new BusinessRuleException('Bekleyen talep sayısı sınırına ulaşıldı. Mevcut talepler karara bağlanınca yeni talep oluşturabilirsiniz.', 'too_many_open_requests', [], 422);
        }

        $row = PackageRequest::query()->create([
            'branch_id' => $student->branch_id,
            'student_id' => $student->id,
            'package_id' => $packageId,
            'kind' => $kind,
            'note' => isset($data['note']) ? trim((string) $data['note']) ?: null : null,
            'status' => 'pending',
            'requested_by' => $request->user()->id,
        ]);

        $who = $this->isGuardian($request) ? 'Veli' : 'Öğrenci';
        $label = $kind === 'coaching' ? 'koçluk' : ('paket'.($packageName ? ' ("'.mb_substr($packageName, 0, 60).'")' : ''));
        Audit::log('package_request.created', sprintf('%s, %s için %s talebi oluşturdu.', $who, $student->full_name, $label), $row);

        return $this->isoJson([
            'message' => 'Talebiniz kuruma iletildi. Durumu bu sayfadan takip edebilirsiniz.',
            'id' => $row->id,
        ], 201);
    }

    /** @return array{active: bool, coach: ?string, next_session_on: ?string, from_package: bool} */
    private function coachingStatus(Student $student): array
    {
        $assignment = $student->activeCoachingAssignment()->with('coach:id,name')->first();
        $nextOn = DB::table('coaching_sessions')->where('student_id', $student->id)->whereNotNull('next_session_on')
            ->where('next_session_on', '>=', CarbonImmutable::today()->toDateString())->min('next_session_on');

        $fromPackage = DB::table('enrollments as e')->join('education_packages as pk', 'pk.id', '=', 'e.education_package_id')
            ->where('e.student_id', $student->id)->whereNull('e.deleted_at')->where('e.status', 'active')
            ->where('pk.has_coaching', true)->exists();

        return [
            'active' => $assignment !== null,
            'coach' => $assignment?->coach?->name,
            'next_session_on' => $nextOn,
            'from_package' => $fromPackage,
        ];
    }

    private function requestRow(PackageRequest $r): array
    {
        return [
            'id' => $r->id,
            'kind' => $r->kind,
            'kind_label' => PackageRequest::KINDS[$r->kind] ?? $r->kind,
            'package' => $r->package?->name,
            'note' => $r->note,
            'status' => $r->status,
            'status_label' => PackageRequest::STATUSES[$r->status] ?? $r->status,
            'decision_note' => $r->decision_note,
            'handled_at' => $r->handled_at?->toAtomString(),
            'created_at' => $r->created_at?->toAtomString(),
        ];
    }

    /** @return array<string, string> */
    private function enrollmentLabels(): array
    {
        return ['active' => 'Aktif', 'frozen' => 'Donduruldu', 'withdrawn' => 'Ayrıldı', 'completed' => 'Tamamlandı', 'pending' => 'Bekliyor'];
    }
}
