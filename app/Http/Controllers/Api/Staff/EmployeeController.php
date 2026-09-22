<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Api\ApiController;
use App\Models\Employee;
use App\Models\User;
use App\Services\Staff\EmployeeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeController extends ApiController
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request);
        $this->applySort($query, $request, ['full_name' => DB::raw("CONCAT(first_name,' ',last_name)"), 'hired_on' => 'hired_on', 'created_at' => 'created_at'], 'full_name');

        return $this->paginated($query->paginate($this->perPage($request)), fn (Employee $e) => $this->row($e));
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'positions' => Employee::query()->distinct()->orderBy('position')->pluck('position'),
            'linkable_users' => User::query()->where('user_type', User::TYPE_STAFF)->whereDoesntHave('teacher')
                ->orderBy('name')->get(['id', 'name', 'username']),
        ]);
    }

    public function show(Employee $employee): JsonResponse
    {
        $employee->load('user:id,username,is_active,last_login_at');

        return response()->json($this->row($employee) + [
            'first_name' => $employee->first_name, 'last_name' => $employee->last_name, 'hired_on' => $employee->hired_on?->toDateString(),
            'user' => $employee->user ? ['id' => $employee->user->id, 'username' => $employee->user->username, 'is_active' => $employee->user->is_active] : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $result = $this->employees->create($data);

        return response()->json(['employee' => $result['employee'], 'temp_password' => $result['temp_password']], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $data = $this->validated($request);
        $employee = $this->employees->update($employee, $data);

        return response()->json(['employee' => $employee]);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->employees->delete($employee);

        return $this->ok('Personel kaydı silindi.');
    }

    private function row(Employee $e): array
    {
        return [
            'id' => $e->id, 'full_name' => $e->full_name, 'position' => $e->position, 'phone' => $e->phone,
            'email' => $e->email, 'hired_on' => $e->hired_on?->toDateString(), 'is_active' => $e->is_active, 'has_user' => (bool) $e->user_id,
            'username' => $e->relationLoaded('user') ? $e->user?->username : null,
            'user_active' => $e->relationLoaded('user') && $e->user ? (bool) $e->user->is_active : null,
            'last_login_at' => $e->relationLoaded('user') ? $e->user?->last_login_at?->toAtomString() : null,
        ];
    }

    private function filtered(Request $request): Builder
    {
        $query = Employee::query()->with('user:id,username,is_active,last_login_at');
        if ($q = trim((string) $request->query('q'))) {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like])->orWhere('position', 'like', $like)->orWhere('phone', 'like', $like));
        }
        if ($request->filled('status')) {
            $query->where('is_active', $request->query('status') === 'active');
        }

        return $query;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80'],
            'position' => ['nullable', 'string', 'max:80'], 'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'], 'hired_on' => ['nullable', 'date'], 'is_active' => ['sometimes', 'boolean'],
            'link_user_id' => ['nullable', 'integer', 'exists:users,id'], 'create_user' => ['sometimes', 'boolean'], 'username' => ['nullable', 'string', 'max:60'],
        ]);
    }
}
