<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Finance\PackageRequestController;
use App\Http\Controllers\Api\Portal\PortalPackagesController;
use App\Http\Middleware\EnsurePortalStudent;
use App\Models\Branch;
use App\Models\CoachingAssignment;
use App\Models\Guardian;
use App\Models\PackageRequest;
use App\Models\Student;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\Permissions;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Portal "Paketlerim": paket + koçluk görünürlüğü, kapsam izolasyonu, talep oluşturma (öğrenci/veli),
 * yönetici onay/ret ve koçluk atama. Bellek içi SQLite üzerinde gerçek migration'lar (canlıya dokunmaz).
 */
class PortalPackagesTest extends TestCase
{
    private Branch $branch;

    private User $admin;

    private int $packageWithCoaching;

    private int $packagePlain;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }
        config(['kurs.silent_events' => true, 'kurs.node' => 'server']);
        Event::fake([\App\Events\StudentCreated::class, \App\Events\StudentStatusChanged::class,
            \App\Events\StudentUpdated::class, \App\Events\EnrollmentCreated::class, \App\Events\PaymentReceived::class]);

        $this->artisan('migrate', ['--force' => true])->run();
        app(SyncSchema::class)->flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branch = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']);
        app(BranchContext::class)->set($this->branch->id);

        foreach (Permissions::all() as $p) {
            Permission::findOrCreate($p, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('yonetici', 'web');
        $role->syncPermissions(Permissions::all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::query()->create(['branch_id' => $this->branch->id, 'name' => 'Müdür', 'username' => 'mudur',
            'user_type' => 'staff', 'password' => 'Parola123!', 'is_active' => true]);
        $this->admin->assignRole('yonetici');

        $termId = DB::table('academic_terms')->insertGetId(['branch_id' => $this->branch->id, 'name' => '2026-2027',
            'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true, 'created_at' => now(), 'updated_at' => now()]);
        $programId = DB::table('programs')->insertGetId(['branch_id' => $this->branch->id, 'code' => 'TYT', 'name' => 'TYT Hazırlık',
            'kind' => 'group', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->packageWithCoaching = DB::table('education_packages')->insertGetId([
            'branch_id' => $this->branch->id, 'program_id' => $programId, 'academic_term_id' => $termId,
            'name' => 'TYT Tam Paket', 'list_price' => '30000.00', 'default_installments' => 10,
            'includes' => "Ders + Deneme + Koçluk", 'has_coaching' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->packagePlain = DB::table('education_packages')->insertGetId([
            'branch_id' => $this->branch->id, 'program_id' => $programId, 'academic_term_id' => $termId,
            'name' => 'TYT Standart', 'list_price' => '18000.00', 'default_installments' => 8,
            'includes' => "Ders + Deneme", 'has_coaching' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function student(string $first): Student
    {
        $s = new Student;
        $s->forceFill(['student_no' => (string) random_int(100000, 999999), 'first_name' => $first, 'last_name' => 'Test',
            'phone' => '0532'.random_int(1000000, 9999999), 'status' => 'active'])->save();

        return $s->refresh();
    }

    private function enroll(Student $s, int $packageId): void
    {
        $termId = DB::table('academic_terms')->value('id');
        $programId = DB::table('programs')->value('id');
        $enrollmentId = DB::table('enrollments')->insertGetId([
            'branch_id' => $this->branch->id, 'student_id' => $s->id, 'academic_term_id' => $termId, 'program_id' => $programId,
            'education_package_id' => $packageId, 'enrollment_no' => 'KYT-'.random_int(10000, 99999), 'list_price' => '30000.00',
            'discount_amount' => '0', 'scholarship_amount' => '0', 'net_price' => '30000.00', 'status' => 'active',
            'enrolled_on' => today()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('installments')->insert([
            ['branch_id' => $this->branch->id, 'enrollment_id' => $enrollmentId, 'student_id' => $s->id, 'sequence' => 1,
                'due_date' => today()->toDateString(), 'amount' => '15000.00', 'paid_amount' => '15000.00', 'status' => 'paid', 'created_at' => now(), 'updated_at' => now()],
            ['branch_id' => $this->branch->id, 'enrollment_id' => $enrollmentId, 'student_id' => $s->id, 'sequence' => 2,
                'due_date' => today()->addMonth()->toDateString(), 'amount' => '15000.00', 'paid_amount' => '0', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function studentUser(Student $s): User
    {
        $u = User::query()->create(['branch_id' => $this->branch->id, 'name' => $s->full_name, 'username' => 'ogr'.$s->id,
            'user_type' => 'student', 'password' => 'Parola123!', 'is_active' => true]);
        $s->forceFill(['user_id' => $u->id])->save();

        return $u;
    }

    /** @param array<string,mixed> $data */
    private function portalRequest(Student $s, User $user, array $data = [], ?Guardian $guardian = null): Request
    {
        $req = Request::create('/', empty($data) ? 'GET' : 'POST', $data);
        $req->setUserResolver(fn () => $user);
        $req->attributes->set(EnsurePortalStudent::ATTRIBUTE, $s);
        $req->attributes->set(EnsurePortalStudent::CHILDREN_ATTRIBUTE, collect([$s]));
        if ($guardian) {
            $req->attributes->set(EnsurePortalStudent::GUARDIAN_ATTRIBUTE, $guardian);
        }

        return $req;
    }

    private function adminRequest(array $data = []): Request
    {
        $req = Request::create('/', 'POST', $data);
        $req->setUserResolver(fn () => $this->admin);

        return $req;
    }

    // ================================================================== kayıt defteri

    public function test_package_requests_table_is_registered_and_synced(): void
    {
        $this->assertTrue(SyncRegistry::isKnown('package_requests'));
        $this->assertTrue(SyncRegistry::get('package_requests')->isSynced());
        $this->assertTrue(app(SyncSchema::class)->hasUuid('package_requests'));
        $this->assertSame([], app(SyncSchema::class)->unresolvedRefs('package_requests'));
    }

    // ================================================================== görünürlük

    public function test_portal_shows_package_content_and_coaching_status(): void
    {
        $s = $this->student('Ayşe');
        $this->enroll($s, $this->packageWithCoaching);
        $user = $this->studentUser($s);

        $data = app(PortalPackagesController::class)->index($this->portalRequest($s, $user))->getData(true);

        $this->assertCount(1, $data['enrollments']);
        $enr = $data['enrollments'][0];
        $this->assertSame('TYT Tam Paket', $enr['package']['name']);
        $this->assertStringContainsString('Koçluk', $enr['package']['includes']);
        $this->assertTrue($enr['package']['has_coaching']);
        $this->assertSame(2, $enr['payment']['installment_count']);
        $this->assertSame(1, $enr['payment']['paid_count']);
        $this->assertSame('15000.00', $enr['payment']['remaining']);

        // Koçluk henüz atanmadı ama paket koçluk içeriyor → talep edilebilir + from_package işaretli
        $this->assertFalse($data['coaching']['active']);
        $this->assertTrue($data['coaching']['from_package']);
        $this->assertTrue($data['coaching_addon_available']);
        $this->assertCount(2, $data['available_packages']);
        $this->assertSame([], $data['requests']);
    }

    public function test_scope_isolation_between_students(): void
    {
        $a = $this->student('Ali');
        $this->enroll($a, $this->packageWithCoaching);
        $b = $this->student('Berk');
        $userB = $this->studentUser($b);

        // B kendi bağlamında A'nın kaydını GÖRMEZ
        $data = app(PortalPackagesController::class)->index($this->portalRequest($b, $userB))->getData(true);
        $this->assertSame([], $data['enrollments']);
    }

    // ================================================================== talep + onay/ret

    public function test_student_creates_package_request_and_duplicate_is_blocked(): void
    {
        $s = $this->student('Cem');
        $user = $this->studentUser($s);

        $res = app(PortalPackagesController::class)->store($this->portalRequest($s, $user, ['kind' => 'package', 'package_id' => $this->packagePlain, 'note' => 'Şubatta']));
        $this->assertSame(201, $res->getStatusCode());
        $row = PackageRequest::query()->where('student_id', $s->id)->firstOrFail();
        $this->assertSame('pending', $row->status);
        $this->assertSame('package', $row->kind);
        $this->assertSame($user->id, $row->requested_by);

        // Aynı paket için ikinci bekleyen talep engellenir
        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        app(PortalPackagesController::class)->store($this->portalRequest($s, $user, ['kind' => 'package', 'package_id' => $this->packagePlain]));
    }

    public function test_package_request_requires_package_id(): void
    {
        $s = $this->student('Deniz');
        $user = $this->studentUser($s);
        $this->expectException(ValidationException::class);
        app(PortalPackagesController::class)->store($this->portalRequest($s, $user, ['kind' => 'package']));
    }

    public function test_admin_approves_coaching_request_and_assigns_coach(): void
    {
        $s = $this->student('Efe');
        $user = $this->studentUser($s);
        app(PortalPackagesController::class)->store($this->portalRequest($s, $user, ['kind' => 'coaching', 'note' => 'Koç istiyorum']));
        $row = PackageRequest::query()->where('student_id', $s->id)->where('kind', 'coaching')->firstOrFail();

        Auth::setUser($this->admin);
        $res = app(PackageRequestController::class)->approve($this->adminRequest(['coach_id' => $this->admin->id, 'decision_note' => 'Onay']), $row->refresh());
        $this->assertSame(200, $res->getStatusCode());

        $row->refresh();
        $this->assertSame('approved', $row->status);
        $this->assertSame($this->admin->id, $row->handled_by);
        $this->assertNotNull($row->handled_at);

        $assignment = CoachingAssignment::query()->where('student_id', $s->id)->where('is_active', true)->first();
        $this->assertNotNull($assignment);
        $this->assertSame($this->admin->id, (int) $assignment->coach_id);

        // Portalda koçluk artık aktif ve add-on talebi kapalı
        $data = app(PortalPackagesController::class)->index($this->portalRequest($s, $user))->getData(true);
        $this->assertTrue($data['coaching']['active']);
        $this->assertFalse($data['coaching_addon_available']);
    }

    public function test_admin_rejects_request(): void
    {
        $s = $this->student('Ferda');
        $user = $this->studentUser($s);
        app(PortalPackagesController::class)->store($this->portalRequest($s, $user, ['kind' => 'package', 'package_id' => $this->packagePlain]));
        $row = PackageRequest::query()->where('student_id', $s->id)->firstOrFail();

        Auth::setUser($this->admin);
        $res = app(PackageRequestController::class)->reject($this->adminRequest(['decision_note' => 'Kontenjan yok']), $row->refresh());
        $this->assertSame(200, $res->getStatusCode());
        $row->refresh();
        $this->assertSame('rejected', $row->status);
        $this->assertSame('Kontenjan yok', $row->decision_note);

        // Karara bağlanmış talep tekrar işlenemez
        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        app(PackageRequestController::class)->approve($this->adminRequest([]), $row->refresh());
    }

    public function test_coaching_request_blocked_when_already_assigned(): void
    {
        $s = $this->student('Gizem');
        $user = $this->studentUser($s);
        Auth::setUser($this->admin);
        app(\App\Services\Coaching\CoachingService::class)->assignCoach($s, $this->admin->id);

        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        app(PortalPackagesController::class)->store($this->portalRequest($s, $user, ['kind' => 'coaching']));
    }
}
