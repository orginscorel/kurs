<?php

namespace App\Http\Controllers\Api\Settings;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Branch;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Auth\SessionDirectory;
use App\Services\Settings\UserAdminService;
use App\Support\Audit;
use App\Support\Permissions;
use App\Sync\Local\LocalSessionDirectory;
use App\Sync\Local\LocalSessionStore;
use App\Sync\Local\SessionReporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends ApiController
{
    private const USER_TYPES = ['staff' => 'Personel', 'teacher' => 'Öğretmen', 'student' => 'Öğrenci', 'guardian' => 'Veli'];

    public function __construct(private readonly UserAdminService $users) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request);
        $this->applySort($query, $request, ['name' => 'name', 'last_login_at' => 'last_login_at', 'created_at' => 'created_at'], 'name');

        return $this->paginated($query->paginate($this->perPage($request)), fn (User $u) => $this->row($u));
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'role_labels' => collect(Permissions::defaultRoles())->map(fn ($r) => $r['label']),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'user_types' => self::USER_TYPES,
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $user->load('roles:id,name', 'branch:id,name');
        $logins = LoginEvent::query()->where('user_id', $user->id)->latest('created_at')->limit(20)->get();
        $notice = null;
        if (config('kurs.node') === 'local') {
            // Yerel kurulum: yalnız bu Mac'teki oturumlar (tüm cihazlar web panelinde)
            $sessions = collect(app(LocalSessionDirectory::class)->localRows($user->id,
                (int) $request->user()->id === (int) $user->id && $request->hasSession() ? LocalSessionStore::hashOf($request->session()->getId()) : null));
            $notice = 'Yalnız bu Mac\'teki oturumlar görünüyor. Kullanıcının tüm cihazlarını web panelinden görüp kapatabilirsiniz.';
        } else {
            $current = (int) $request->user()->id === (int) $user->id ? SessionDirectory::currentOf($request) : [];
            $sessions = app(SessionDirectory::class)->forUser($user, $current);
        }

        return response()->json($this->row($user) + [
            'email' => $user->email, 'recent_logins' => $logins, 'sessions' => $sessions->values(), 'sessions_notice' => $notice,
        ]);
    }

    /** Yönetici: kullanıcının bir oturumunu kapatır (web / Mac uygulaması / mobil). */
    public function revokeSession(Request $request, User $user, string $id): JsonResponse
    {
        if (config('kurs.node') === 'local') {
            return $this->ok($this->revokeLocal($request, $user, [$id]));
        }
        $current = (int) $request->user()->id === (int) $user->id ? SessionDirectory::currentOf($request) : [];

        return $this->ok(app(SessionDirectory::class)->revoke($user, $id, $request->user(), $current));
    }

    /** Yönetici: kullanıcının tüm oturumları (kendisiyse mevcut oturumu hariç). */
    public function revokeAllSessions(Request $request, User $user): JsonResponse
    {
        if (config('kurs.node') === 'local') {
            $ids = array_map(fn ($r) => $r['id'], app(LocalSessionDirectory::class)->localRows($user->id));

            return $this->ok($this->revokeLocal($request, $user, $ids));
        }
        $current = (int) $request->user()->id === (int) $user->id ? SessionDirectory::currentOf($request) : [];
        $res = app(SessionDirectory::class)->revokeOthers($user, $request->user(), $current);

        return response()->json($res);
    }

    /** Yerel kurulumda yönetici yalnız bu Mac'teki oturumları kapatabilir. */
    private function revokeLocal(Request $request, User $user, array $ids): string
    {
        $store = app(LocalSessionStore::class);
        $hashes = [];
        foreach ($ids as $id) {
            if (! str_starts_with($id, 'app:') || ! app(LocalSessionDirectory::class)->isLocal($user->id, substr($id, 4))) {
                throw new BusinessRuleException('Bu oturum başka bir cihazda. Kullanıcının diğer oturumlarını web panelinden kapatın.', 'remote_session', [], 409);
            }
            $hashes[] = substr($id, 4);
        }
        $store->deleteByHashes($hashes, $user->id, $request->hasSession() ? $request->session()->getId() : null);
        Audit::log('auth.session_revoked', sprintf('%s kullanıcısının bu cihazdaki %d oturumunu kapattı.', $user->name, count($hashes)), $user);
        app(SessionReporter::class)->runSafely(true);

        return $hashes ? 'Oturum kapatıldı.' : 'Kapatılacak oturum yok.';
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $result = $this->users->create($data);

        return response()->json(['user' => $result['user'], 'temp_password' => $result['temp_password']], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $this->validated($request, $user);
        $user = $this->users->update($user, $data);

        return response()->json(['user' => $user]);
    }

    public function toggleActive(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $user = $this->users->toggleActive($user, $data['is_active'], $request->user());

        return response()->json(['user' => $user]);
    }

    public function resetPassword(User $user): JsonResponse
    {
        $result = $this->users->resetPassword($user);

        return response()->json($result);
    }

    private function row(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'phone' => $user->phone,
            'email' => $user->email,
            'user_type' => $user->user_type, 'user_type_label' => self::USER_TYPES[$user->user_type] ?? $user->user_type,
            'branch' => $user->relationLoaded('branch') && $user->branch ? ['id' => $user->branch->id, 'name' => $user->branch->name] : null,
            'roles' => $user->relationLoaded('roles') ? $user->roles->pluck('name') : $user->getRoleNames(),
            'is_active' => $user->is_active, 'must_change_password' => $user->must_change_password,
            'last_login_at' => $user->last_login_at?->toIso8601String(), 'last_login_ip' => $user->last_login_ip,
        ];
    }

    private function filtered(Request $request): Builder
    {
        $query = User::query()->with('roles:id,name');

        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('username', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"));
        }
        if ($type = $request->query('user_type')) {
            $query->where('user_type', $type);
        }
        if ($role = $request->query('role')) {
            $query->whereHas('roles', fn ($w) => $w->where('name', $role));
        }
        if ($request->filled('status')) {
            $query->where('is_active', $request->query('status') === 'active');
        }
        if ($branchId = $request->query('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'username' => ['required', 'string', 'max:60', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'user_type' => ['required', Rule::in(['staff', 'teacher', 'student', 'guardian'])],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'roles' => ['sometimes', 'array'], 'roles.*' => ['string', 'exists:roles,name'],
            'password' => ['sometimes', 'nullable', 'string', 'min:10'],
        ], [
            'username.unique' => 'Bu kullanıcı adı başka bir hesapta kullanılıyor.',
            'email.unique' => 'Bu e-posta adresi başka bir hesapta kayıtlı.',
        ], [
            'name' => 'Ad soyad', 'username' => 'Kullanıcı adı', 'email' => 'E-posta', 'phone' => 'Telefon',
            'user_type' => 'Hesap türü', 'branch_id' => 'Şube', 'roles' => 'Roller', 'roles.*' => 'Rol', 'password' => 'Şifre',
        ]);

        return $data;
    }
}
