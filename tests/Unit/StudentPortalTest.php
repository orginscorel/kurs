<?php

namespace Tests\Unit;

use App\Http\Middleware\BlockWritesWhileImpersonating;
use App\Http\Middleware\EnsurePortalStudent;
use App\Http\Middleware\EnsureStaffUser;
use App\Models\User;
use App\Services\Auth\Impersonation;
use App\Services\Students\StudentAccountService;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Öğrenci portalı yetki sınırları (DB'siz): rota tanımları, ara katmanlar, önizleme yazma kilidi.
 */
class StudentPortalTest extends TestCase
{
    private function user(string $type, int $id = 10): User
    {
        $u = new User;
        $u->forceFill(['id' => $id, 'user_type' => $type, 'name' => 'Test', 'is_active' => true]);

        return $u;
    }

    private function request(string $method, string $uri, ?User $user, array $session = []): Request
    {
        $request = Request::create($uri, $method);
        $request->setUserResolver(fn () => $user);
        $store = new Store('test', new ArraySessionHandler(10));
        foreach ($session as $k => $v) {
            $store->put($k, $v);
        }
        $request->setLaravelSession($store);

        return $request;
    }

    /** @return list<RoutingRoute> */
    private function apiRoutes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/v1/')));
    }

    public function test_generated_password_is_readable_and_mixed(): void
    {
        $seen = [];
        for ($i = 0; $i < 300; $i++) {
            $p = StudentAccountService::generatePassword();
            $this->assertSame(StudentAccountService::PASSWORD_LENGTH, strlen($p));
            $this->assertMatchesRegularExpression('/^[abcdefghjkmnpqrstuvwxyz23456789]+$/', $p, 'Karışan karakter (0/o/1/l/i) olmamalı');
            $this->assertGreaterThanOrEqual(2, preg_match_all('/\d/', $p));
            $this->assertGreaterThanOrEqual(2, preg_match_all('/[a-z]/', $p));
            $seen[$p] = true;
        }
        $this->assertGreaterThan(290, count($seen), 'Şifreler rastgele olmalı');
    }

    public function test_portal_routes_never_take_a_student_id_and_writes_are_whitelisted(): void
    {
        $portal = array_filter($this->apiRoutes(), fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/v1/portal'));
        $this->assertNotEmpty($portal);

        // Yazma uçları yalnız bunlar: ödev teslimi/görüldü/dosya silme (öğrenci), duyuru okundu, veli talebi
        $writes = [
            'POST api/v1/portal/homework/{submission}/submit',
            'POST api/v1/portal/homework/{submission}/seen',
            'DELETE api/v1/portal/homework/{submission}/files/{document}',
            'POST api/v1/portal/announcements/{announcement}/read',
            'POST api/v1/portal/requests',
        ];
        $seenWrites = [];

        foreach ($portal as $route) {
            $middleware = array_values(array_diff($route->gatherMiddleware(), $route->excludedMiddleware()));
            $this->assertContains('portal.student', $middleware, $route->uri());
            $this->assertContains('auth:sanctum', $middleware, $route->uri());
            $this->assertNotContains('staff', $middleware, $route->uri());
            // İzinli parametreler: kendi sonucu / teslimi / dosyası / duyuru (sahiplik denetleyicide sınanır)
            $this->assertEmpty(array_diff($route->parameterNames(), ['result', 'submission', 'document', 'announcement', 'defense']), $route->uri());
            $this->assertStringNotContainsString('student', implode(',', $route->parameterNames()));

            $methods = array_diff($route->methods(), ['GET', 'HEAD']);
            // Disiplin modülünün portal uçları kendi testlerinde sınanır
            if ($methods && ! str_starts_with($route->uri(), 'api/v1/portal/discipline')) {
                $key = implode('|', $methods).' '.$route->uri();
                $this->assertContains($key, $writes, "Beklenmeyen portal yazma ucu: {$key}");
                $this->assertContains('throttle:writes', $middleware, $key);
                $seenWrites[] = $key;
            }
        }
        $this->assertEqualsCanonicalizing($writes, $seenWrites);
    }

    public function test_management_routes_are_closed_to_portal_accounts(): void
    {
        $open = ['api/v1/auth/', 'api/v1/portal', 'api/v1/gateway/', 'api/v1/notifications', 'api/v1/client-errors'];

        foreach ($this->apiRoutes() as $route) {
            $middleware = array_values(array_diff($route->gatherMiddleware(), $route->excludedMiddleware()));
            if (! in_array('auth:sanctum', $middleware, true)) {
                continue; // oturumsuz uçlar (webhook, giriş) ayrı doğrulanır
            }
            $isOpen = collect($open)->contains(fn ($p) => str_starts_with($route->uri(), $p));
            if ($isOpen) {
                continue;
            }
            if (str_starts_with($route->uri(), 'api/v1/teacher-portal')) {
                // Öğretmen portalı: yönetim değil; her uç öğretmen kapsamına kilitli (TeacherPortalTest ayrıca sınar)
                $this->assertContains('portal.teacher', $middleware, $route->uri());
                $this->assertNotContains('staff', $middleware, $route->uri());

                continue;
            }
            $hasStaff = in_array('staff', $middleware, true);
            $hasPermission = collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));
            $this->assertTrue($hasStaff || $hasPermission, "Yönetim ucu portal hesabına açık: {$route->uri()}");
        }
    }

    public function test_student_account_routes_require_new_permissions(): void
    {
        $expect = [
            'api/v1/students/{student}/portal-account/credentials' => 'permission:students.credentials',
            'api/v1/students/{student}/portal-account/reset-password' => 'permission:students.credentials',
            'api/v1/students/{student}/impersonate' => 'permission:students.impersonate',
        ];
        $found = [];
        foreach ($this->apiRoutes() as $route) {
            if (isset($expect[$route->uri()])) {
                $this->assertContains($expect[$route->uri()], $route->gatherMiddleware(), $route->uri());
                $this->assertContains('staff', $route->gatherMiddleware(), $route->uri());
                $found[] = $route->uri();
            }
        }
        $this->assertEqualsCanonicalizing(array_keys($expect), array_unique($found));
    }

    public function test_staff_middleware_blocks_student_and_guardian_accounts(): void
    {
        $mw = new EnsureStaffUser;
        $next = fn () => new Response('ok');

        foreach ([User::TYPE_STUDENT, User::TYPE_GUARDIAN] as $type) {
            $this->assertSame(403, $mw->handle($this->request('GET', '/api/v1/students', $this->user($type)), $next)->getStatusCode());
        }
        $this->assertSame(200, $mw->handle($this->request('GET', '/api/v1/students', $this->user(User::TYPE_STAFF)), $next)->getStatusCode());

        // Yalnız 'ogretmen' rolü olan öğretmen hesabı = öğretmen portalı → yönetim uçları kapalı
        $portalTeacher = $this->user(User::TYPE_TEACHER)->setRelation('roles', collect([new Role(['name' => 'ogretmen'])]));
        $this->assertSame(403, $mw->handle($this->request('GET', '/api/v1/students', $portalTeacher), $next)->getStatusCode());
        // Ek yönetim rolü (ör. rehber) verilmiş öğretmen personel sayılır
        $staffTeacher = $this->user(User::TYPE_TEACHER)->setRelation('roles', collect([new Role(['name' => 'ogretmen']), new Role(['name' => 'rehber'])]));
        $this->assertSame(200, $mw->handle($this->request('GET', '/api/v1/students', $staffTeacher), $next)->getStatusCode());
    }

    public function test_portal_middleware_rejects_staff_accounts(): void
    {
        // Veli hesapları kendi bağlı öğrencileriyle açılır: GuardianPortalTest (veritabanlı) sınar.
        $mw = new EnsurePortalStudent;
        $next = fn () => new Response('ok');

        foreach ([User::TYPE_STAFF, User::TYPE_TEACHER] as $type) {
            $this->assertSame(403, $mw->handle($this->request('GET', '/api/v1/portal/summary', $this->user($type)), $next)->getStatusCode());
        }
    }

    public function test_writes_are_blocked_while_impersonating(): void
    {
        $mw = new BlockWritesWhileImpersonating;
        $next = fn () => new Response('ok');
        $student = $this->user(User::TYPE_STUDENT, 82);
        $session = [Impersonation::SESSION_KEY => ['impersonator_id' => 1, 'impersonator_name' => 'Y', 'student_id' => 5, 'student_name' => 'Ö', 'user_id' => 82, 'started_at' => now()->toAtomString()]];

        $this->assertSame(403, $mw->handle($this->request('POST', '/api/v1/auth/change-password', $student, $session), $next)->getStatusCode());
        $this->assertSame(403, $mw->handle($this->request('DELETE', '/api/v1/auth/sessions/x', $student, $session), $next)->getStatusCode());
        $this->assertSame(200, $mw->handle($this->request('GET', '/api/v1/portal/summary', $student, $session), $next)->getStatusCode());
        $this->assertSame(200, $mw->handle($this->request('POST', '/api/v1/auth/impersonation/leave', $student, $session), $next)->getStatusCode());
        $this->assertSame(200, $mw->handle($this->request('POST', '/api/v1/auth/logout', $student, $session), $next)->getStatusCode());

        // Öğrencinin kendi (önizlemesiz) oturumunda şifre değiştirme serbest
        $this->assertSame(200, $mw->handle($this->request('POST', '/api/v1/auth/change-password', $student), $next)->getStatusCode());
        // Oturumdaki kayıt başka kullanıcıya aitse (bayat) kilit uygulanmaz ve önizleme sayılmaz
        $other = $this->user(User::TYPE_STAFF, 1);
        $this->assertNull(Impersonation::activeFor($this->request('GET', '/', $other, $session)));
    }

    public function test_impersonation_public_payload_hides_internal_ids(): void
    {
        $payload = Impersonation::publicPayload(['impersonator_id' => 1, 'impersonator_name' => 'Y', 'student_id' => 5, 'student_name' => 'Ö', 'user_id' => 82, 'started_at' => 'x']);
        $this->assertArrayNotHasKey('impersonator_id', $payload);
        $this->assertArrayNotHasKey('user_id', $payload);
        $this->assertNull(Impersonation::publicPayload(null));
    }

    public function test_new_permissions_are_catalogued_and_granted_to_expected_roles(): void
    {
        $all = Permissions::all();
        $this->assertContains('students.credentials', $all);
        $this->assertContains('students.impersonate', $all);

        $roles = Permissions::defaultRoles();
        foreach (['mudur', 'danisman', 'yonetici'] as $role) {
            $this->assertContains('students.credentials', $roles[$role]['permissions'], $role);
            $this->assertContains('students.impersonate', $roles[$role]['permissions'], $role);
        }
        foreach (['ogretmen', 'muhasebe', 'rehber', 'ogrenci', 'veli'] as $role) {
            $this->assertNotContains('students.impersonate', $roles[$role]['permissions'], $role);
        }
        $this->assertSame([], $roles['ogrenci']['permissions']);
    }

    public function test_portal_query_parameter_is_limited_to_student_id(): void
    {
        // Veli portalı öğrenci seçimini yalnız ?student_id ile yapar; yol parametresi olarak öğrenci id'si yoktur.
        $portal = array_filter($this->apiRoutes(), fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/v1/portal'));
        $this->assertContains('api/v1/portal/context', array_map(fn ($r) => $r->uri(), $portal));
    }

    public function test_initial_password_is_hidden_and_encrypted(): void
    {
        $u = new User;
        $this->assertContains('initial_password', $u->getHidden());
        $this->assertSame('encrypted', $u->getCasts()['initial_password']);

        $u->forceFill(['initial_password' => 'abc2345']);
        $this->assertNotSame('abc2345', $u->getAttributes()['initial_password']);
        $this->assertSame('abc2345', $u->initial_password);
        $this->assertArrayNotHasKey('initial_password', $u->toArray());
    }
}
