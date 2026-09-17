<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ApiController;
use App\Services\Settings\RoleService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class RoleController extends ApiController
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): JsonResponse
    {
        $labels = collect(Permissions::defaultRoles())->map(fn ($r) => $r['label']);
        // Not: Role::users() spatie'nin morphedByMany ilişkisi 'sanctum' guard'ında model çözemiyor;
        // kullanıcı sayısı doğrudan pivot tablodan hesaplanır.
        $userCounts = DB::table('model_has_roles')->where('model_type', 'user')
            ->select('role_id', DB::raw('COUNT(*) AS c'))->groupBy('role_id')->pluck('c', 'role_id');

        $roles = Role::query()->with('permissions:id,name')->orderBy('name')->get()->map(fn (Role $r) => [
            'id' => $r->id, 'name' => $r->name, 'label' => $labels[$r->name] ?? $r->name,
            'is_protected' => $r->name === RoleService::PROTECTED_ROLE,
            'user_count' => (int) ($userCounts[$r->id] ?? 0),
            'permissions' => $r->name === RoleService::PROTECTED_ROLE ? Permissions::all() : $r->permissions->pluck('name')->all(),
        ]);

        return response()->json(['data' => $roles, 'catalog' => Permissions::catalog()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'min:2'], 'permissions' => ['sometimes', 'array'], 'permissions.*' => ['string'],
        ]);
        $role = $this->roles->create($data['name'], $data['permissions'] ?? []);

        return response()->json($role, 201);
    }

    public function copy(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60', 'min:2']]);
        $copy = $this->roles->copy($role, $data['name']);

        return response()->json($copy, 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate(['permissions' => ['required', 'array'], 'permissions.*' => ['string']]);
        $role = $this->roles->update($role, $data['permissions']);

        return response()->json($role);
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->roles->delete($role);

        return $this->ok('Rol silindi.');
    }
}
