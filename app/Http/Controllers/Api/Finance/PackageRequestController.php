<?php

namespace App\Http\Controllers\Api\Finance;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\PackageRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\Coaching\CoachingService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Yönetici tarafı: portaldan gelen paket / koçluk taleplerini görme ve karara bağlama.
 * Onay: paket talebinde yönetici kaydı/planı elle düzenler (kayıt oluşturma akışına yönlendirilir);
 * koçluk talebinde istenirse aynı ekranda koç atanır (CoachingService::assignCoach, coaching.manage ile).
 * Ret: talep kapatılır. Talebin durumu talep sahibine portalda görünür.
 * Şube kapsamı PackageRequest'in BelongsToBranch global kapsamıyla otomatiktir.
 */
class PackageRequestController extends ApiController
{
    public function __construct(private readonly CoachingService $coaching) {}

    public function index(Request $request): JsonResponse
    {
        $status = in_array($request->query('status'), array_keys(PackageRequest::STATUSES), true) ? $request->query('status') : null;

        $query = PackageRequest::query()
            ->with(['student:id,full_name,student_no', 'package:id,name,has_coaching', 'requester:id,name', 'handler:id,name'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request->filled('kind') && in_array($request->query('kind'), array_keys(PackageRequest::KINDS), true),
                fn ($q) => $q->where('kind', $request->query('kind')))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('id');

        $paginator = $query->paginate($this->perPage($request, 25));

        return $this->paginated($paginator, fn (PackageRequest $r) => [
            'id' => $r->id,
            'kind' => $r->kind,
            'kind_label' => PackageRequest::KINDS[$r->kind] ?? $r->kind,
            'status' => $r->status,
            'status_label' => PackageRequest::STATUSES[$r->status] ?? $r->status,
            'note' => $r->note,
            'decision_note' => $r->decision_note,
            'student' => $r->student ? ['id' => $r->student->id, 'full_name' => $r->student->full_name, 'student_no' => $r->student->student_no] : null,
            'package' => $r->package ? ['id' => $r->package->id, 'name' => $r->package->name, 'has_coaching' => (bool) $r->package->has_coaching] : null,
            'requested_by' => $r->requester?->name,
            'handled_by' => $r->handler?->name,
            'handled_at' => $r->handled_at?->toAtomString(),
            'created_at' => $r->created_at?->toAtomString(),
        ], [
            'pending_count' => PackageRequest::query()->where('status', 'pending')->count(),
        ]);
    }

    public function approve(Request $request, PackageRequest $packageRequest): JsonResponse
    {
        $this->assertPending($packageRequest);

        $data = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:500'],
            // Koçluk talebinde (ya da koçluk içeren pakette) istenirse aynı anda koç atanabilir
            'coach_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ], [], ['coach_id' => 'Koç', 'decision_note' => 'Karar notu']);

        $student = Student::query()->findOrFail($packageRequest->student_id);
        $coachAssigned = false;

        if (! empty($data['coach_id'])) {
            if (! $request->user()->can('coaching.manage')) {
                throw new BusinessRuleException('Koç atamak için koçluk yönetimi yetkiniz yok.', 'coaching_forbidden', [], 403);
            }
            $this->coaching->assignCoach($student, (int) $data['coach_id'], $packageRequest->note);
            $coachAssigned = true;
        }

        $packageRequest->forceFill([
            'status' => 'approved',
            'handled_by' => $request->user()->id,
            'handled_at' => now(),
            'decision_note' => isset($data['decision_note']) ? (trim((string) $data['decision_note']) ?: null) : null,
        ])->save();

        Audit::log('package_request.approved', sprintf('%s için %s talebini onayladı%s.',
            $student->full_name, PackageRequest::KINDS[$packageRequest->kind] ?? $packageRequest->kind, $coachAssigned ? ' ve koç atadı' : ''), $packageRequest);

        // Paket talebinde kayıt/plan yönetici tarafından elle düzenlenir (otomatik finans işlemi yok).
        $enrollTo = $packageRequest->kind === 'package'
            ? '/finans/kayitlar/yeni?ogrenci='.$student->id.($packageRequest->package_id ? '&paket='.$packageRequest->package_id : '')
            : null;

        return $this->ok('Talep onaylandı.', [
            'coach_assigned' => $coachAssigned,
            'enroll_to' => $enrollTo,
            'student_id' => $student->id,
        ]);
    }

    public function reject(Request $request, PackageRequest $packageRequest): JsonResponse
    {
        $this->assertPending($packageRequest);

        $data = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:500'],
        ], [], ['decision_note' => 'Karar notu']);

        $packageRequest->forceFill([
            'status' => 'rejected',
            'handled_by' => $request->user()->id,
            'handled_at' => now(),
            'decision_note' => isset($data['decision_note']) ? (trim((string) $data['decision_note']) ?: null) : null,
        ])->save();

        $student = Student::query()->find($packageRequest->student_id);
        Audit::log('package_request.rejected', sprintf('%s için %s talebini reddetti.',
            $student?->full_name ?? '—', PackageRequest::KINDS[$packageRequest->kind] ?? $packageRequest->kind), $packageRequest);

        return $this->ok('Talep reddedildi.');
    }

    /** Koç atama seçenekleri (koçluk talebini onaylarken). */
    public function coaches(): JsonResponse
    {
        return response()->json([
            'coaches' => User::query()->where('is_active', true)->whereIn('user_type', ['staff', 'teacher'])
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function assertPending(PackageRequest $packageRequest): void
    {
        if ($packageRequest->status !== 'pending') {
            throw new BusinessRuleException('Bu talep zaten karara bağlanmış.', 'already_handled', [], 422);
        }
    }
}
