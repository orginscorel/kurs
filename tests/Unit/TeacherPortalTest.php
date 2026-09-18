<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\TeacherPortal\TeacherPortalController;
use App\Http\Middleware\BlockWritesWhileImpersonating;
use App\Http\Middleware\EnsurePortalTeacher;
use App\Http\Middleware\EnsureStaffUser;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Auth\Impersonation;
use App\Services\Teachers\TeacherScope;
use App\Exceptions\BusinessRuleException;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Öğretmen portalı yetki sınırı: öğretmen yalnız kendi sınıflarının öğrencilerini görür (başka sınıf → 403),
 * yönetim ve finans uçlarına giremez (403), yazma yetkisi olmadan / önizlemede yazamaz (403),
 * başka öğretmenin ödevini göremez (404). Bellek içi SQLite üzerinde asgari şema kurulur (canlı veritabanına dokunmaz).
 */
class TeacherPortalTest extends TestCase
{
    private User $teacherUser;

    private Teacher $teacher;

    private Teacher $otherTeacher;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }

        (require base_path('database/migrations/2026_09_14_194640_create_permission_tables.php'))->up();

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('name');
            $t->string('username', 60)->unique();
            $t->string('email')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('user_type', 20)->default('staff');
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->text('initial_password')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->timestamp('password_changed_at')->nullable();
            $t->string('avatar_path')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('teachers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('first_name');
            $t->string('last_name');
            $t->string('title')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->string('student_no');
            $t->string('first_name');
            $t->string('last_name');
            $t->string('full_name')->nullable();
            $t->string('status')->default('active');
            $t->unsignedBigInteger('guidance_teacher_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('class_groups', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->string('name');
            $t->unsignedBigInteger('advisor_teacher_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('class_group_student', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_group_id');
            $t->unsignedBigInteger('student_id');
            $t->date('left_on')->nullable();
        });
        Schema::create('lesson_schedules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_group_id');
            $t->unsignedBigInteger('teacher_id');
            $t->unsignedBigInteger('subject_id');
            $t->date('valid_until')->nullable();
            $t->softDeletes();
        });
        Schema::create('lesson_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_group_id');
            $t->unsignedBigInteger('teacher_id');
            $t->date('date');
        });
        Schema::create('study_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('teacher_id');
            $t->dateTime('starts_at');
            $t->string('status');
        });
        Schema::create('study_session_student', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('study_session_id');
            $t->unsignedBigInteger('student_id');
        });
        Schema::create('homework', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('teacher_id');
            $t->unsignedBigInteger('class_group_id')->nullable();
            $t->unsignedBigInteger('subject_id');
            $t->string('title');
            $t->dateTime('assigned_at')->nullable();
            $t->dateTime('due_at');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('homework_submissions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('homework_id');
            $t->unsignedBigInteger('student_id');
            $t->string('status')->default('assigned');
            $t->timestamps();
        });
        Schema::create('teacher_subject', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('teacher_id');
            $t->unsignedBigInteger('subject_id');
        });
        Schema::create('student_observations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('teacher_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('class_group_id')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('kind');
            $t->string('category');
            $t->integer('points')->default(0);
            $t->text('body');
            $t->boolean('visible_to_guardian')->default(false);
            $t->boolean('visible_to_student')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('description', 500);
            $t->text('changes')->nullable();
            $t->string('ip_address')->nullable();
            $t->string('user_agent')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('tokenable_type');
            $t->unsignedBigInteger('tokenable_id');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (Permissions::defaultRoles()['ogretmen']['permissions'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        Role::findOrCreate('ogretmen', 'web')->syncPermissions(Permissions::defaultRoles()['ogretmen']['permissions']);
        Role::findOrCreate('rehber', 'web');

        // Sınıf 1: öğretmenin ders programında; sınıf 2: başka öğretmenin
        DB::table('class_groups')->insert([
            ['id' => 1, 'branch_id' => 1, 'name' => '9-A', 'is_active' => true],
            ['id' => 2, 'branch_id' => 1, 'name' => '9-B', 'is_active' => true],
        ]);
        DB::table('students')->insert([
            ['id' => 1, 'branch_id' => 1, 'student_no' => '1001', 'first_name' => 'Ali', 'last_name' => 'Kaya', 'full_name' => 'Ali Kaya', 'status' => 'active'],
            ['id' => 2, 'branch_id' => 1, 'student_no' => '1002', 'first_name' => 'Ece', 'last_name' => 'Tan', 'full_name' => 'Ece Tan', 'status' => 'active'],
            ['id' => 3, 'branch_id' => 1, 'student_no' => '1003', 'first_name' => 'Can', 'last_name' => 'Er', 'full_name' => 'Can Er', 'status' => 'withdrawn'],
        ]);
        DB::table('class_group_student')->insert([
            ['class_group_id' => 1, 'student_id' => 1],
            ['class_group_id' => 2, 'student_id' => 2],
            ['class_group_id' => 1, 'student_id' => 3],
        ]);

        $this->teacherUser = $this->makeUser('ogretmen.a', User::TYPE_TEACHER, ['ogretmen']);
        $other = $this->makeUser('ogretmen.b', User::TYPE_TEACHER, ['ogretmen']);
        $this->teacher = Teacher::query()->create(['branch_id' => 1, 'user_id' => $this->teacherUser->id, 'first_name' => 'Ayşe', 'last_name' => 'Yıl', 'is_active' => true]);
        $this->otherTeacher = Teacher::query()->create(['branch_id' => 1, 'user_id' => $other->id, 'first_name' => 'Mert', 'last_name' => 'Su', 'is_active' => true]);

        DB::table('lesson_schedules')->insert([
            ['class_group_id' => 1, 'teacher_id' => $this->teacher->id, 'subject_id' => 5],
            ['class_group_id' => 2, 'teacher_id' => $this->otherTeacher->id, 'subject_id' => 6],
        ]);
        DB::table('homework')->insert([
            'id' => 50, 'branch_id' => 1, 'teacher_id' => $this->otherTeacher->id, 'class_group_id' => 2, 'subject_id' => 6,
            'title' => 'Başkasının ödevi', 'assigned_at' => now(), 'due_at' => now()->addDays(3), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(string $username, string $type, array $roles): User
    {
        $u = User::query()->forceCreate([
            'branch_id' => 1, 'name' => $username, 'username' => $username, 'user_type' => $type,
            'password' => bcrypt('x'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $u->syncRoles($roles);

        return $u->refresh();
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

    // ------------------------------------------------------------------ kapsam

    public function test_scope_contains_only_own_class_students_and_skips_closed_ones(): void
    {
        $scope = new TeacherScope($this->teacher);

        $this->assertEquals([1], $scope->groupIds()->all());
        $this->assertTrue($scope->hasStudent(1));
        $this->assertFalse($scope->hasStudent(2), 'Başka sınıfın öğrencisi kapsam dışında olmalı');
        $this->assertFalse($scope->hasStudent(3), 'Ayrılmış öğrenci kapsam dışında olmalı');

        try {
            $scope->assertStudent(2);
            $this->fail('Başka sınıfın öğrencisi için hata beklenirdi.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(403, $e->status);
        }
    }

    public function test_advisor_and_study_students_join_scope(): void
    {
        DB::table('class_groups')->where('id', 2)->update(['advisor_teacher_id' => $this->teacher->id]);
        $this->assertTrue((new TeacherScope($this->teacher))->hasStudent(2));
    }

    public function test_foreign_student_detail_returns_403_over_http(): void
    {
        Sanctum::actingAs($this->teacherUser);

        $this->getJson('/api/v1/teacher-portal/students/2')->assertStatus(403);
        $this->getJson('/api/v1/teacher-portal/students/3')->assertStatus(403);
        $this->postJson('/api/v1/teacher-portal/students/2/observations', [
            'kind' => 'positive', 'category' => 'participation', 'points' => 1, 'body' => 'Deneme notu',
        ])->assertStatus(403);
        $this->assertSame(0, DB::table('student_observations')->count());
    }

    public function test_foreign_class_seating_plan_returns_403(): void
    {
        Sanctum::actingAs($this->teacherUser);
        // Oturma planı (salt okunur) yalnız öğretmenin kendi sınıfı için
        $this->getJson('/api/v1/teacher-portal/classes/2/seating')->assertStatus(403);
    }

    public function test_own_student_observation_is_saved_and_points_must_match_kind(): void
    {
        Sanctum::actingAs($this->teacherUser);

        $this->postJson('/api/v1/teacher-portal/students/1/observations', [
            'kind' => 'positive', 'category' => 'participation', 'points' => -2, 'body' => 'Tutarsız puan',
        ])->assertStatus(422);

        $this->postJson('/api/v1/teacher-portal/students/1/observations', [
            'kind' => 'positive', 'category' => 'participation', 'points' => 2, 'body' => 'Derse aktif katıldı.', 'visible_to_guardian' => true,
        ])->assertStatus(201);

        $row = DB::table('student_observations')->first();
        $this->assertSame($this->teacher->id, (int) $row->teacher_id);
        $this->assertSame(2, (int) $row->points);

        // Başka öğretmen bu notu silemez
        Sanctum::actingAs(User::query()->where('username', 'ogretmen.b')->first());
        $this->deleteJson('/api/v1/teacher-portal/observations/'.$row->id)->assertStatus(404);
    }

    public function test_other_teachers_homework_is_not_found(): void
    {
        Sanctum::actingAs($this->teacherUser);
        $this->getJson('/api/v1/teacher-portal/homework/50')->assertStatus(404);
        $this->putJson('/api/v1/teacher-portal/homework/50/submissions', ['rows' => [['student_id' => 2, 'score' => 90]]])->assertStatus(404);
    }

    // ------------------------------------------------------------------ yönetim / finans kapalı

    public function test_portal_teacher_cannot_reach_management_or_finance_routes(): void
    {
        Sanctum::actingAs($this->teacherUser);

        foreach (['/api/v1/students', '/api/v1/teachers', '/api/v1/finance/installments', '/api/v1/finance/payments', '/api/v1/dashboard'] as $uri) {
            $status = $this->getJson($uri)->getStatusCode();
            $this->assertContains($status, [403, 404], "{$uri} öğretmen portalı hesabına açık ({$status})");
        }

        // Rota tanımı düzeyinde: tüm finans uçları 'staff' ara katmanı ile sarılı
        $finance = array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/v1/finance'));
        $this->assertNotEmpty($finance);
        foreach ($finance as $route) {
            $this->assertContains('staff', $route->gatherMiddleware(), $route->uri());
        }

        // Ara katman düzeyinde: yalnız öğretmen rolü → personel değil
        $mw = new EnsureStaffUser;
        $res = $mw->handle($this->request('GET', '/api/v1/finance/payments', $this->teacherUser), fn () => new Response('ok'));
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_default_teacher_role_has_no_management_permissions(): void
    {
        $perms = Permissions::defaultRoles()['ogretmen']['permissions'];
        foreach ($perms as $p) {
            $this->assertStringStartsWith('teacher_portal.', $p);
        }
        $this->assertTrue($this->teacherUser->isTeacherPortalUser());
        $this->assertSame('teacher', $this->teacherUser->portalKind());
        $this->assertFalse($this->teacherUser->isStaff());

        // Ek yönetim rolü verilen öğretmen personel sayılır, portal kilidi kalkar
        $this->teacherUser->assignRole('rehber');
        $fresh = $this->teacherUser->fresh();
        $this->assertTrue($fresh->isStaff());
        $this->assertNull($fresh->portalKind());
    }

    public function test_teacher_portal_routes_are_scoped_and_outside_staff(): void
    {
        $routes = array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/v1/teacher-portal'));
        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $mw = array_values(array_diff($route->gatherMiddleware(), $route->excludedMiddleware()));
            $this->assertContains('portal.teacher', $mw, $route->uri());
            $this->assertContains('auth:sanctum', $mw, $route->uri());
            $this->assertContains('impersonation.readonly', $mw, $route->uri());
            $this->assertNotContains('staff', $mw, $route->uri());
            $this->assertStringNotContainsString('teacher', implode(',', $route->parameterNames()), 'Öğretmen id istemciden alınmamalı');
            if (array_diff($route->methods(), ['GET', 'HEAD'])) {
                $this->assertTrue(collect($mw)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), 'Yazma ucunda hız sınırı yok: '.$route->uri());
            }
        }
    }

    // ------------------------------------------------------------------ ara katmanlar

    public function test_portal_teacher_middleware_rejects_non_teachers_and_missing_permission(): void
    {
        $mw = new EnsurePortalTeacher;
        $next = fn () => new Response('ok');

        $staff = $this->makeUser('personel', User::TYPE_STAFF, []);
        $this->assertSame(403, $mw->handle($this->request('GET', '/api/v1/teacher-portal/summary', $staff), $next)->getStatusCode());
        $student = $this->makeUser('ogrenci', User::TYPE_STUDENT, []);
        $this->assertSame(403, $mw->handle($this->request('GET', '/api/v1/teacher-portal/summary', $student), $next)->getStatusCode());

        $this->assertSame(200, $mw->handle($this->request('GET', '/api/v1/teacher-portal/summary', $this->teacherUser), $next)->getStatusCode());

        // Yoklama yetkisi alınmış öğretmen yoklama kaydedemez
        $limited = $this->makeUser('ogretmen.c', User::TYPE_TEACHER, []);
        $limited->givePermissionTo('teacher_portal.access', 'teacher_portal.homework');
        Teacher::query()->create(['branch_id' => 1, 'user_id' => $limited->id, 'first_name' => 'Kısıtlı', 'last_name' => 'Hoca', 'is_active' => true]);
        $limited = $limited->fresh();
        $this->assertSame(403, $mw->handle($this->request('POST', '/api/v1/teacher-portal/attendance/1', $limited), $next, 'teacher_portal.attendance')->getStatusCode());
        $this->assertSame(200, $mw->handle($this->request('POST', '/api/v1/teacher-portal/homework', $limited), $next, 'teacher_portal.homework')->getStatusCode());

        // Pasif öğretmen kaydı → kapalı
        $this->teacher->forceFill(['is_active' => false])->save();
        $this->assertSame(403, $mw->handle($this->request('GET', '/api/v1/teacher-portal/summary', $this->teacherUser), $next)->getStatusCode());
    }

    public function test_writes_are_blocked_in_teacher_preview(): void
    {
        $mw = new BlockWritesWhileImpersonating;
        $next = fn () => new Response('ok');
        $session = [Impersonation::SESSION_KEY => Impersonation::make(Impersonation::KIND_TEACHER, 1, 'Yönetici', $this->teacher->id, 'Ayşe Yıl', $this->teacherUser->id)];

        foreach ([['POST', 'attendance/5'], ['POST', 'homework'], ['PUT', 'homework/7/submissions'], ['POST', 'students/1/observations'], ['DELETE', 'observations/3'], ['POST', 'requests/2/respond']] as [$m, $path]) {
            $this->assertSame(403, $mw->handle($this->request($m, "/api/v1/teacher-portal/{$path}", $this->teacherUser, $session), $next)->getStatusCode(), "{$m} {$path}");
        }
        $this->assertSame(200, $mw->handle($this->request('GET', '/api/v1/teacher-portal/summary', $this->teacherUser, $session), $next)->getStatusCode());
        $this->assertSame(200, $mw->handle($this->request('POST', '/api/v1/auth/impersonation/leave', $this->teacherUser, $session), $next)->getStatusCode());
        $this->assertSame('/ogretmenler/'.$this->teacher->id, Impersonation::returnPath($session[Impersonation::SESSION_KEY]));
    }

    public function test_teacher_account_routes_require_permissions(): void
    {
        $expect = [
            'GET api/v1/teachers/{teacher}/portal-account/credentials' => 'permission:teachers.credentials',
            'POST api/v1/teachers/{teacher}/portal-account/reset-password' => 'permission:teachers.credentials',
            'POST api/v1/teachers/{teacher}/impersonate' => 'permission:teachers.impersonate',
        ];
        $found = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $key = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first().' '.$route->uri();
            if (isset($expect[$key])) {
                $this->assertContains($expect[$key], $route->gatherMiddleware(), $key);
                $this->assertContains('staff', $route->gatherMiddleware(), $key);
                $found[] = $key;
            }
        }
        $this->assertEqualsCanonicalizing(array_keys($expect), $found);

        $roles = Permissions::defaultRoles();
        foreach (['ogretmen', 'muhasebe', 'ogrenci', 'veli'] as $role) {
            $this->assertNotContains('teachers.impersonate', $roles[$role]['permissions'], $role);
            $this->assertNotContains('teachers.credentials', $roles[$role]['permissions'], $role);
        }
    }

    // ------------------------------------------------------------------ yoklama penceresi

    public function test_attendance_window(): void
    {
        $now = CarbonImmutable::parse('2026-09-17 18:00:00');
        $open = fn (string $status, string $date, string $start) => TeacherPortalController::attendanceWindowOpen($status, $date, $start, $now);

        $this->assertTrue($open('scheduled', '2026-09-17', '2026-09-17 18:10:00'), '15 dk kala açılır');
        $this->assertFalse($open('scheduled', '2026-09-17', '2026-09-17 18:30:00'), 'Daha erken kapalı');
        $this->assertTrue($open('scheduled', '2026-09-10', '2026-09-10 18:00:00'), '7 gün geriye açık');
        $this->assertFalse($open('scheduled', '2026-09-09', '2026-09-09 18:00:00'), '8 gün önce kapalı');
        $this->assertFalse($open('cancelled', '2026-09-17', '2026-09-17 17:00:00'), 'İptal ders kapalı');
    }
}
