<?php

namespace Tests\Unit;

use App\Events\StudentStatusChanged;
use App\Http\Controllers\Api\Portal\PortalController;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePortalStudent;
use App\Listeners\Portal\SyncPortalAccountsOnStatusChange;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Services\Auth\Impersonation;
use App\Services\Guardians\GuardianAccountService;
use App\Services\Students\StudentAccountService;
use App\Support\Permissions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Veli portalı yetki sınırları, ilk girişte şifre zorunluluğu ve hesap kapanma kuralları.
 * Bellek içi SQLite üzerinde asgari şema kurulur (canlı veritabanına ASLA dokunmaz).
 */
class GuardianPortalTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Güvenlik: yalnız bellek içi sqlite üzerinde çalış
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('name');
            $t->string('username', 60)->unique();
            $t->string('email')->nullable()->unique();
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
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('student_no');
            $t->string('first_name');
            $t->string('last_name');
            $t->string('full_name')->nullable();
            $t->string('status')->default('active');
            $t->string('photo_path')->nullable();
            $t->string('phone')->nullable();
            $t->unsignedBigInteger('guidance_teacher_id')->nullable();
            $t->string('target_university')->nullable();
            $t->string('target_department')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('guardians', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id')->nullable()->unique();
            $t->string('first_name');
            $t->string('last_name');
            $t->string('phone')->nullable();
            $t->string('whatsapp_phone')->nullable();
            $t->string('email')->nullable();
            $t->string('occupation')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('guardian_student', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('guardian_id');
            $t->unsignedBigInteger('student_id');
            $t->string('relationship')->default('parent');
            $t->boolean('is_primary')->default(false);
            $t->boolean('is_financially_responsible')->default(false);
            $t->boolean('receives_notifications')->default(true);
            $t->timestamps();
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->text('payload')->nullable();
            $t->integer('last_activity')->default(0);
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('tokenable_type');
            $t->unsignedBigInteger('tokenable_id');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->timestamps();
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
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
        });
        Schema::create('guidance_meetings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('counselor_id')->nullable();
            $t->dateTime('met_at');
            $t->string('kind');
            $t->text('summary');
            $t->string('goal')->nullable();
            $t->text('private_note')->nullable();
            $t->string('visibility')->default('staff');
            $t->boolean('visible_to_student')->default(false);
            $t->date('next_meeting_on')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('class_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->softDeletes();
        });
        Schema::create('class_group_student', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('class_group_id');
            $t->unsignedBigInteger('student_id');
            $t->date('left_on')->nullable();
        });
        Schema::create('student_goals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('student_id');
            $t->boolean('is_active')->default(true);
            $t->string('university')->nullable();
            $t->string('department')->nullable();
            $t->integer('target_rank')->nullable();
            $t->decimal('target_tyt_net')->nullable();
            $t->decimal('target_ayt_net')->nullable();
            $t->text('subject_targets')->nullable();
            $t->timestamps();
        });
    }

    // ---------------------------------------------------------------- yardımcılar

    private function student(string $status = 'active', ?string $name = 'Ali'): Student
    {
        $this->seq++;
        $s = new Student;
        $s->forceFill(['branch_id' => 1, 'student_no' => (string) (2026900 + $this->seq), 'first_name' => $name.$this->seq, 'last_name' => 'Test', 'status' => $status])->save();

        return $s;
    }

    private function guardian(?string $phone, array $children = [], ?string $whatsapp = null): Guardian
    {
        $g = new Guardian;
        $g->forceFill(['branch_id' => 1, 'first_name' => 'Veli'.(++$this->seq), 'last_name' => 'Test', 'phone' => $phone, 'whatsapp_phone' => $whatsapp])->save();
        foreach ($children as $i => $child) {
            $g->students()->attach($child->id, ['relationship' => 'mother', 'is_primary' => $i === 0]);
        }

        return $g;
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

    /** Ara katmandan geçen isteğin çözdüğü öğrenci id'si ya da durum kodu. */
    private function resolve(User $user, string $query = ''): int|string
    {
        $request = $this->request('GET', '/api/v1/portal/summary'.$query, $user);
        $response = (new EnsurePortalStudent)->handle($request, fn () => new Response('ok'));

        return $response->getStatusCode() === 200 ? $request->attributes->get(EnsurePortalStudent::ATTRIBUTE)->id : 'HTTP '.$response->getStatusCode();
    }

    private function accounts(): GuardianAccountService
    {
        return app(GuardianAccountService::class);
    }

    // ---------------------------------------------------------------- kullanıcı adı / hesap açma

    public function test_username_is_normalized_mobile_phone(): void
    {
        $this->assertSame('05321234567', GuardianAccountService::usernameFor('0532 123 45 67'));
        $this->assertSame('05321234567', GuardianAccountService::usernameFor('+90 (532) 123-4567'));
        $this->assertSame('05321234567', GuardianAccountService::usernameFor('5321234567'));
        $this->assertSame('05321234567', GuardianAccountService::usernameFor('00905321234567'));
        $this->assertNull(GuardianAccountService::usernameFor('0356 123 45 67'), 'Sabit hat kullanıcı adı olamaz');
        $this->assertNull(GuardianAccountService::usernameFor('12345'));
        $this->assertNull(GuardianAccountService::usernameFor(null));
        $this->assertNull(GuardianAccountService::usernameFor('admin'));
    }

    public function test_account_is_created_with_encrypted_initial_password_and_forced_change(): void
    {
        $g = $this->guardian('0532 111 22 33', [$this->student()]);
        $result = $this->accounts()->ensure($g);

        $this->assertTrue($result['created']);
        $user = $result['user']->fresh();
        $this->assertSame('05321112233', $user->username);
        $this->assertSame(User::TYPE_GUARDIAN, $user->user_type);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->requiresPasswordChange());
        $this->assertNotSame($user->initial_password, $user->getAttributes()['initial_password'], 'Başlangıç şifresi şifreli saklanmalı');
        $this->assertTrue(password_verify($user->initial_password, $user->password));
        $this->assertSame($user->id, $g->fresh()->user_id);

        // İdempotent
        $again = $this->accounts()->ensure($g->fresh());
        $this->assertFalse($again['created']);
        $this->assertSame($user->id, $again['user']->id);
        $this->assertSame(1, User::query()->where('user_type', 'guardian')->count());
    }

    public function test_whatsapp_number_is_used_when_phone_is_missing(): void
    {
        $g = $this->guardian(null, [$this->student()], '05442223344');
        $this->assertSame('05442223344', $this->accounts()->ensure($g)['user']->username);
    }

    public function test_missing_or_conflicting_phone_never_creates_or_shares_an_account(): void
    {
        $child = $this->student();
        $noPhone = $this->guardian(null, [$child]);
        $landline = $this->guardian('0356 222 11 00', [$child]);
        $this->assertSame(GuardianAccountService::PROBLEM_PHONE_MISSING, $this->accounts()->ensure($noPhone)['problem']);
        $this->assertSame(GuardianAccountService::PROBLEM_PHONE_MISSING, $this->accounts()->ensure($landline)['problem']);

        $first = $this->guardian('05329998877', [$child]);
        $second = $this->guardian('0532 999 88 77', [$this->student()]);
        $this->assertTrue($this->accounts()->ensure($first)['created']);
        $conflict = $this->accounts()->ensure($second);
        $this->assertNull($conflict['user']);
        $this->assertSame(GuardianAccountService::PROBLEM_PHONE_CONFLICT, $conflict['problem']);
        $this->assertSame($first->id, $conflict['conflict']['guardian_id']);
        $this->assertNull($second->fresh()->user_id, 'Çakışan veli başka velinin hesabına bağlanmamalı');

        // Personel kullanıcı adıyla çakışma da hesap açtırmaz
        User::query()->forceCreate(['name' => 'P', 'username' => '05556667788', 'password' => 'x', 'user_type' => 'staff']);
        $third = $this->guardian('05556667788', [$child]);
        $this->assertSame(GuardianAccountService::PROBLEM_PHONE_CONFLICT, $this->accounts()->ensure($third)['problem']);
        $this->assertFalse($this->accounts()->ensure($third)['conflict']['is_guardian']);
    }

    public function test_phone_change_moves_username_only_when_free(): void
    {
        $g = $this->guardian('05321000001', [$this->student()]);
        $user = $this->accounts()->ensure($g)['user'];
        $other = $this->guardian('05321000002', [$this->student()]);
        $this->accounts()->ensure($other);

        $g->forceFill(['phone' => '05321000003'])->save();
        $this->assertNull($this->accounts()->syncUsername($g));
        $this->assertSame('05321000003', $user->fresh()->username);

        $g->forceFill(['phone' => '05321000002'])->save();
        $this->assertSame(GuardianAccountService::PROBLEM_PHONE_CONFLICT, $this->accounts()->syncUsername($g));
        $this->assertSame('05321000003', $user->fresh()->username, 'Çakışmada eski kullanıcı adı korunur');
    }

    public function test_password_reset_forces_change_again_and_closes_sessions(): void
    {
        $g = $this->guardian('05321000011', [$this->student()]);
        $user = $this->accounts()->ensure($g)['user'];
        $user->forceFill(['initial_password' => null, 'must_change_password' => false, 'password_changed_at' => now()])->save();
        $this->assertFalse($user->fresh()->requiresPasswordChange());
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'last_activity' => time()]);

        $password = $this->accounts()->resetPassword($g->fresh());

        $user = $user->fresh();
        $this->assertSame($password, $user->initial_password);
        $this->assertTrue($user->requiresPasswordChange());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    // ---------------------------------------------------------------- yetki: yalnız kendi çocukları

    public function test_guardian_can_only_resolve_own_active_children(): void
    {
        $a = $this->student();
        $b = $this->student();
        $withdrawn = $this->student('withdrawn');
        $foreign = $this->student();
        $g = $this->guardian('05321000021', [$a, $b, $withdrawn]);
        $otherParent = $this->guardian('05321000022', [$foreign]);
        $user = $this->accounts()->ensure($g)['user'];
        $this->accounts()->ensure($otherParent);

        $this->assertSame($a->id, $this->resolve($user), 'Varsayılan: birincil velisi olduğu öğrenci');
        $this->assertSame($b->id, $this->resolve($user, '?student_id='.$b->id));
        $this->assertSame('HTTP 403', $this->resolve($user, '?student_id='.$foreign->id), 'Başka velinin çocuğu');
        $this->assertSame('HTTP 403', $this->resolve($user, '?student_id='.$withdrawn->id), 'Ayrılan çocuk portalda görünmez');
        $this->assertSame('HTTP 403', $this->resolve($user, '?student_id=999999'));
        $this->assertSame('HTTP 403', $this->resolve($user, '?student_id='.urlencode($a->id.' OR 1=1')));
        $this->assertSame('HTTP 403', $this->resolve($user, '?student_id[]='.$a->id));

        // Silinen (arşiv) çocuk da görünmez
        $b->delete();
        $this->assertSame('HTTP 403', $this->resolve($user, '?student_id='.$b->id));
    }

    public function test_student_account_ignores_student_id_parameter(): void
    {
        $me = $this->student();
        $other = $this->student();
        $user = app(StudentAccountService::class)->ensure($me, audit: false)['user'];

        $this->assertSame($me->id, $this->resolve($user, '?student_id='.$other->id));
    }

    public function test_guardian_without_open_children_or_record_is_rejected(): void
    {
        $g = $this->guardian('05321000031', [$this->student('graduated')]);
        $user = $this->accounts()->ensure($g)['user'];
        $this->assertSame('HTTP 403', $this->resolve($user));

        $orphan = User::query()->forceCreate(['name' => 'X', 'username' => 'orphan', 'password' => 'x', 'user_type' => 'guardian']);
        $this->assertSame('HTTP 403', $this->resolve($orphan));

        foreach (['staff', 'teacher'] as $type) {
            $u = User::query()->forceCreate(['name' => 'S', 'username' => 'u-'.$type, 'password' => 'x', 'user_type' => $type]);
            $this->assertSame('HTTP 403', $this->resolve($u, '?student_id=1'));
        }
    }

    public function test_context_lists_only_own_children(): void
    {
        $a = $this->student();
        $b = $this->student();
        $this->student(); // başka ailenin çocuğu
        $g = $this->guardian('05321000041', [$a, $b]);
        $user = $this->accounts()->ensure($g)['user'];
        $this->actingAs($user);
        DB::table('class_groups')->insert(['id' => 7, 'name' => '11-A']);
        DB::table('class_group_student')->insert(['class_group_id' => 7, 'student_id' => $b->id]);

        $request = $this->request('GET', '/api/v1/portal/context?student_id='.$b->id, $user);
        (new EnsurePortalStudent)->handle($request, fn () => new Response('ok'));
        $data = app(PortalController::class)->context($request)->getData(true);

        $this->assertSame('guardian', $data['role']);
        $this->assertSame($b->id, $data['selected_student_id']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($data['students'], 'id'));
        $this->assertSame(['11-A'], collect($data['students'])->firstWhere('id', $b->id)['class_groups']);
    }

    public function test_guidance_visibility_differs_by_role_and_private_note_never_leaks(): void
    {
        $child = $this->student();
        $g = $this->guardian('05321000051', [$child]);
        $guardianUser = $this->accounts()->ensure($g)['user'];
        $studentUser = app(StudentAccountService::class)->ensure($child, audit: false)['user'];

        $rows = [
            ['summary' => 'personel-ogrenciye-acik', 'visibility' => 'staff', 'visible_to_student' => true],
            ['summary' => 'veliye-acik', 'visibility' => 'guardian', 'visible_to_student' => false],
            ['summary' => 'herkese-acik', 'visibility' => 'guardian', 'visible_to_student' => true],
            ['summary' => 'yalniz-rehber', 'visibility' => 'counselor', 'visible_to_student' => true],
            ['summary' => 'yalniz-personel', 'visibility' => 'staff', 'visible_to_student' => false],
        ];
        foreach ($rows as $r) {
            DB::table('guidance_meetings')->insert($r + ['branch_id' => 1, 'student_id' => $child->id, 'met_at' => now(), 'kind' => 'individual', 'private_note' => 'GIZLI-NOT']);
        }

        $summaries = function (User $user) {
            $this->actingAs($user);
            $request = $this->request('GET', '/api/v1/portal/guidance', $user);
            (new EnsurePortalStudent)->handle($request, fn () => new Response('ok'));
            $json = app(PortalController::class)->guidance($request)->getContent();
            $this->assertStringNotContainsString('GIZLI-NOT', $json);
            $this->assertStringNotContainsString('private_note', $json);

            return array_column(json_decode($json, true)['meetings'], 'summary');
        };

        $this->assertEqualsCanonicalizing(['personel-ogrenciye-acik', 'herkese-acik'], $summaries($studentUser));
        $this->assertEqualsCanonicalizing(['veliye-acik', 'herkese-acik'], $summaries($guardianUser));
    }

    // ---------------------------------------------------------------- ilk girişte şifre zorunluluğu

    public function test_initial_password_blocks_portal_until_changed(): void
    {
        $child = $this->student();
        $studentUser = app(StudentAccountService::class)->ensure($child, audit: false)['user'];
        $guardianUser = $this->accounts()->ensure($this->guardian('05321000061', [$child]))['user'];
        $mw = new EnsurePasswordChanged;
        $next = fn () => new Response('ok');

        foreach ([$studentUser, $guardianUser] as $user) {
            $user = $user->fresh();
            $this->assertTrue($user->requiresPasswordChange());
            foreach (['GET /api/v1/portal/summary', 'GET /api/v1/portal/context', 'GET /api/v1/notifications', 'GET /api/v1/auth/sessions', 'POST /api/v1/notifications/read'] as $call) {
                [$m, $uri] = explode(' ', $call);
                $response = $mw->handle($this->request($m, $uri, $user), $next);
                $this->assertSame(403, $response->getStatusCode(), $call);
                $this->assertSame('password_change_required', json_decode($response->getContent(), true)['error_code']);
            }
            foreach (['GET /api/v1/auth/me', 'POST /api/v1/auth/change-password', 'POST /api/v1/auth/logout', 'POST /api/v1/client-errors'] as $call) {
                [$m, $uri] = explode(' ', $call);
                $this->assertSame(200, $mw->handle($this->request($m, $uri, $user), $next)->getStatusCode(), $call);
            }

            // Önizleme muaf (yazma kilidi ayrı ara katmanda sürer)
            $session = [Impersonation::SESSION_KEY => Impersonation::make($user->isGuardian() ? 'guardian' : 'student', 1, 'Yönetici', 5, 'X', $user->id)];
            $this->assertSame(200, $mw->handle($this->request('GET', '/api/v1/portal/summary', $user, $session), $next)->getStatusCode());

            // Şifre değişince serbest
            $user->forceFill(['initial_password' => null, 'must_change_password' => false, 'password_changed_at' => now()])->save();
            $this->assertSame(200, $mw->handle($this->request('GET', '/api/v1/portal/summary', $user->fresh()), $next)->getStatusCode());
        }

        // Personel: portal kilidi uygulanmaz
        $staff = User::query()->forceCreate(['name' => 'S', 'username' => 'staff1', 'password' => 'x', 'user_type' => 'staff', 'initial_password' => 'abc']);
        $this->assertSame(200, $mw->handle($this->request('GET', '/api/v1/students', $staff), $next)->getStatusCode());
    }

    public function test_password_middleware_is_on_every_authenticated_route(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $mw = $route->gatherMiddleware();
            if (str_starts_with($route->uri(), 'api/v1/') && in_array('auth:sanctum', $mw, true)) {
                $this->assertContains('password.fresh', $mw, $route->uri());
            }
        }
    }

    // ---------------------------------------------------------------- hesap kapanma

    public function test_withdrawn_student_account_closes_and_reopens(): void
    {
        $child = $this->student();
        $sibling = $this->student();
        $single = $this->guardian('05321000071', [$child]);
        $both = $this->guardian('05321000072', [$child, $sibling]);
        $studentUser = app(StudentAccountService::class)->ensure($child, audit: false)['user'];
        $singleUser = $this->accounts()->ensure($single)['user'];
        $bothUser = $this->accounts()->ensure($both)['user'];
        DB::table('sessions')->insert([['id' => 's1', 'user_id' => $studentUser->id], ['id' => 's2', 'user_id' => $singleUser->id], ['id' => 's3', 'user_id' => $bothUser->id]]);
        DB::table('personal_access_tokens')->insert(['tokenable_type' => $studentUser->getMorphClass(), 'tokenable_id' => $studentUser->id, 'name' => 'tel', 'token' => str_repeat('a', 64)]);

        $listener = app(SyncPortalAccountsOnStatusChange::class);

        $child->forceFill(['status' => 'withdrawn'])->save();
        $listener->handle(new StudentStatusChanged($child->id, 'active', 'withdrawn'));

        $this->assertFalse($studentUser->fresh()->is_active);
        $this->assertFalse($singleUser->fresh()->is_active, 'Tek çocuğu ayrılan velinin hesabı kapanır');
        $this->assertTrue($bothUser->fresh()->is_active, 'Başka açık çocuğu olan veli açık kalır');
        $this->assertSame(0, DB::table('sessions')->whereIn('user_id', [$studentUser->id, $singleUser->id])->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $bothUser->id)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $studentUser->id)->count());

        // Kardeş de mezun → ikinci veli de kapanır
        $sibling->forceFill(['status' => 'graduated'])->save();
        $listener->handle(new StudentStatusChanged($sibling->id, 'active', 'graduated'));
        $this->assertFalse($bothUser->fresh()->is_active);

        // Geri dönüş (donduruldu da açık sayılır)
        $child->forceFill(['status' => 'frozen'])->save();
        $listener->handle(new StudentStatusChanged($child->id, 'withdrawn', 'frozen'));
        $this->assertTrue($studentUser->fresh()->is_active);
        $this->assertTrue($singleUser->fresh()->is_active);
        $this->assertTrue($bothUser->fresh()->is_active);

        // İdempotent: tekrar çalıştırmak durum değiştirmez
        $this->assertFalse(app(StudentAccountService::class)->syncActive($child->fresh()));
        $this->assertFalse($this->accounts()->syncActive($single->fresh()));
    }

    public function test_new_account_for_closed_student_starts_inactive(): void
    {
        $gone = $this->student('graduated');
        $this->assertFalse(app(StudentAccountService::class)->ensure($gone, audit: false)['user']->is_active);
        $this->assertFalse($this->accounts()->ensure($this->guardian('05321000081', [$gone]))['user']->is_active);
    }

    public function test_status_listener_is_registered(): void
    {
        $listeners = collect(app('events')->getRawListeners()[StudentStatusChanged::class] ?? [])
            ->map(fn ($l) => is_string($l) ? $l : (is_array($l) ? implode('@', (array) $l) : ''))->implode(',');
        $this->assertStringContainsString(SyncPortalAccountsOnStatusChange::class, $listeners);
    }

    // ---------------------------------------------------------------- rotalar / yetkiler / önizleme

    public function test_guardian_account_routes_require_permissions_and_staff(): void
    {
        $expect = [
            'api/v1/guardians/{guardian}/portal-account' => 'permission:guardians',
            'api/v1/guardians/{guardian}/portal-account/credentials' => 'permission:guardians.credentials',
            'api/v1/guardians/{guardian}/portal-account/reset-password' => 'permission:guardians.credentials',
            'api/v1/guardians/{guardian}/impersonate' => 'permission:guardians.impersonate',
            'api/v1/automations/recommended/enable' => 'permission:automations.manage',
        ];
        $found = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (isset($expect[$route->uri()])) {
                $mw = $route->gatherMiddleware();
                $this->assertContains('staff', $mw, $route->uri());
                $this->assertTrue(collect($mw)->contains(fn ($m) => is_string($m) && str_starts_with($m, $expect[$route->uri()])), $route->uri());
                $found[] = $route->uri();
            }
        }
        $this->assertEqualsCanonicalizing(array_keys($expect), array_values(array_unique($found)));
    }

    public function test_guardian_permissions_are_catalogued(): void
    {
        $all = Permissions::all();
        $this->assertContains('guardians.credentials', $all);
        $this->assertContains('guardians.impersonate', $all);
        $roles = Permissions::defaultRoles();
        foreach (['mudur', 'danisman', 'yonetici'] as $role) {
            $this->assertContains('guardians.impersonate', $roles[$role]['permissions'], $role);
        }
        foreach (['ogretmen', 'muhasebe', 'rehber', 'veli', 'ogrenci'] as $role) {
            $this->assertNotContains('guardians.impersonate', $roles[$role]['permissions'], $role);
            $this->assertNotContains('guardians.credentials', $roles[$role]['permissions'], $role);
        }
        $this->assertSame([], $roles['veli']['permissions']);
    }

    public function test_guardian_impersonation_payload_and_return_path(): void
    {
        $data = Impersonation::make(Impersonation::KIND_GUARDIAN, 1, 'Yönetici', 42, 'Ayşe Veli', 99);
        $public = Impersonation::publicPayload($data);
        $this->assertSame('guardian', $public['kind']);
        $this->assertSame('Ayşe Veli', $public['target_name']);
        $this->assertArrayNotHasKey('impersonator_id', $public);
        $this->assertArrayNotHasKey('user_id', $public);
        $this->assertSame('/veliler/42', Impersonation::returnPath($data));

        // Eski biçimli (öğrenci) oturum kaydı geriye uyumlu
        $legacy = ['impersonator_id' => 1, 'impersonator_name' => 'Y', 'student_id' => 5, 'student_name' => 'Ö', 'user_id' => 82, 'started_at' => 'x'];
        $this->assertSame('/ogrenciler/5', Impersonation::returnPath($legacy));
        $this->assertSame('student', Impersonation::publicPayload($legacy)['kind']);
    }
}
