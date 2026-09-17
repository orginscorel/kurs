<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\ApiController;
use App\Models\DeviceIdentity;
use App\Services\Attendance\DeviceService;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Öğrenci ↔ cihaz kullanıcı no / kart UID eşlemesi. KVKK: ham biyometrik veri yok. */
class DeviceIdentityController extends ApiController
{
    public function __construct(private readonly DeviceService $devices) {}

    public function index(Request $request): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();

        $query = DeviceIdentity::query()->withoutGlobalScope('branch')->where('device_identities.branch_id', $branchId)
            ->join('students as st', 'st.id', '=', 'device_identities.person_id')
            ->where('device_identities.person_type', 'student')
            ->select(['device_identities.*', 'st.full_name', 'st.student_no']);

        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($w) => $w->where('st.full_name', 'like', "%{$q}%")->orWhere('st.student_no', 'like', "%{$q}%")->orWhere('device_identities.identifier', 'like', "%{$q}%"));
        }
        if ($kind = $request->query('kind')) {
            $query->where('device_identities.kind', $kind);
        }

        return $this->paginated($query->orderByDesc('device_identities.id')->paginate($this->perPage($request, 30)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')],
            'kind' => ['required', Rule::in(['fingerprint', 'card', 'qr'])],
            'identifier' => ['required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ], [], ['student_id' => 'Öğrenci', 'kind' => 'Kimlik türü', 'identifier' => 'Kart / parmak izi numarası', 'is_active' => 'Durum']);

        $identity = $this->devices->upsertIdentity($data);

        return response()->json(['message' => 'Kimlik eşlemesi kaydedildi.', 'id' => $identity->id], 201);
    }

    public function destroy(DeviceIdentity $identity): JsonResponse
    {
        $this->devices->deleteIdentity($identity);

        return $this->ok('Kimlik eşlemesi silindi.');
    }

    /** CSV toplu içe aktarma: student_no,kind,identifier[,is_active] */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $result = $this->devices->importIdentities($request->file('file')->getRealPath());

        return response()->json(['message' => "{$result['imported']} eşleme içe aktarıldı, {$result['skipped']} atlandı."] + $result);
    }
}
