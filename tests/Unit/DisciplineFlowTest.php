<?php

namespace Tests\Unit;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\Discipline\DisciplinePortalController;
use App\Http\Middleware\EnsurePortalStudent;
use App\Models\DisciplineBehavior;
use App\Models\DisciplineSanction;
use App\Models\DisciplineSanctionType;
use App\Models\Student;
use App\Models\User;
use App\Services\Discipline\DisciplineBoardService;
use App\Services\Discipline\DisciplineService;
use App\Services\Discipline\DisciplineSettings;
use App\Services\Discipline\SuspensionCalendar;
use App\Services\Reports\DisciplineReportService;
use App\Support\BranchContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Disiplin süreci uçtan uca (bellek içi SQLite; asıl migration ile kurulan şema):
 * olay → savunma → kurul → uzaklaştırma çakışması, portal görünürlüğü ve rapor toplamları.
 */
class DisciplineFlowTest extends TestCase
{
    private User $boss;

    private User $counselor;

    /** @var list<int> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }
        config(['kurs.silent_events' => true]);

        Schema::create('branches', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->boolean('is_active')->default(true), $t->timestamps()]);
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('name');
            $t->string('username')->unique();
            $t->string('user_type', 20)->default('staff');
            $t->string('password')->default('x');
            $t->boolean('is_active')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->text('initial_password')->nullable();
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
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('teachers', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('branch_id')->nullable(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('first_name'), $t->string('last_name'), $t->boolean('is_active')->default(true), $t->timestamps()]);
        Schema::create('subjects', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->timestamps()]);
        Schema::create('class_groups', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->boolean('is_active')->default(true), $t->timestamps(), $t->softDeletes()]);
        Schema::create('class_group_student', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('class_group_id'), $t->unsignedBigInteger('student_id'), $t->date('left_on')->nullable()]);
        Schema::create('academic_terms', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('branch_id'), $t->string('name'), $t->date('starts_on'), $t->date('ends_on'), $t->boolean('is_current')->default(false), $t->timestamps()]);
        Schema::create('settings', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('branch_id')->nullable(), $t->string('group'), $t->string('key'), $t->json('value')->nullable(), $t->timestamps()]);
        Schema::create('sequences', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('branch_id')->nullable(), $t->string('name'), $t->unsignedInteger('year'), $t->unsignedBigInteger('last_value')->default(0), $t->unique(['branch_id', 'name', 'year'])]);
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
        Schema::create('guardians', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('branch_id'), $t->unsignedBigInteger('user_id')->nullable(), $t->string('first_name'), $t->string('last_name'), $t->string('phone')->nullable(), $t->string('whatsapp_phone')->nullable(), $t->timestamps(), $t->softDeletes()]);
        Schema::create('guardian_student', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('guardian_id'), $t->unsignedBigInteger('student_id'), $t->string('relationship')->nullable(), $t->boolean('is_primary')->default(false), $t->boolean('is_financially_responsible')->default(false), $t->boolean('receives_notifications')->default(true), $t->timestamps()]);
        Schema::create('permissions', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('guard_name'), $t->timestamps()]);
        Schema::create('roles', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('guard_name'), $t->timestamps()]);
        Schema::create('role_has_permissions', fn (Blueprint $t) => [$t->unsignedBigInteger('permission_id'), $t->unsignedBigInteger('role_id'), $t->primary(['permission_id', 'role_id'])]);
        Schema::create('model_has_permissions', fn (Blueprint $t) => [$t->unsignedBigInteger('permission_id'), $t->string('model_type'), $t->unsignedBigInteger('model_id')]);
        Schema::create('model_has_roles', fn (Blueprint $t) => [$t->unsignedBigInteger('role_id'), $t->string('model_type'), $t->unsignedBigInteger('model_id')]);

        DB::table('branches')->insert(['id' => 1, 'name' => 'Merkez', 'is_active' => true]);
        DB::table('academic_terms')->insert(['branch_id' => 1, 'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true]);
        foreach (['yonetici', 'rehber'] as $r) {
            DB::table('roles')->insert(['name' => $r, 'guard_name' => 'web']);
        }

        // Asıl migration: şema + varsayılan katalog + yetkiler (idempotent)
        $migration = require base_path('database/migrations/2026_09_17_710100_create_discipline_tables.php');
        $migration->up();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->boss = $this->user('mudur', 'yonetici');
        $this->counselor = $this->user('rehber', 'rehber');
        DB::table('class_groups')->insert([['id' => 1, 'name' => '10-A'], ['id' => 2, 'name' => '10-B']]);
        foreach ([1, 1, 2, 2] as $i => $cg) {
            $s = new Student;
            $s->forceFill(['branch_id' => 1, 'student_no' => (string) (900 + $i), 'first_name' => 'Öğr'.$i, 'last_name' => 'Test', 'status' => 'active'])->save();
            DB::table('class_group_student')->insert(['class_group_id' => $cg, 'student_id' => $s->id]);
            $this->students[] = $s->id;
        }
        app(BranchContext::class)->set(1);
        $this->actingAs($this->boss);
    }

    private function user(string $name, string $role): User
    {
        $u = new User;
        $u->forceFill(['branch_id' => 1, 'name' => ucfirst($name), 'username' => $name, 'user_type' => 'staff', 'password' => 'x'])->save();
        $u->assignRole($role);

        return $u->fresh();
    }

    private function b(string $code): int
    {
        return (int) DisciplineBehavior::query()->where('code', $code)->value('id');
    }

    private function t(string $code): int
    {
        return (int) DisciplineSanctionType::query()->where('code', $code)->value('id');
    }

    private function svc(): DisciplineService
    {
        return app(DisciplineService::class);
    }

    public function test_migration_seeds_catalog_idempotently_and_grants_roles(): void
    {
        $this->assertSame(count(\App\Support\Discipline\DisciplineCatalog::defaultBehaviors()), DisciplineBehavior::query()->count());
        $this->assertSame(7, DisciplineSanctionType::query()->count());
        $this->assertTrue($this->boss->can('discipline.board'));
        $this->assertTrue($this->counselor->can('discipline.create'));
        $this->assertFalse($this->counselor->can('discipline.decide'));
    }

    public function test_quick_incident_points_and_status_flow(): void
    {
        $i = $this->svc()->createIncident(['occurred_at' => '2026-09-10 10:00:00', 'students' => [
            ['student_id' => $this->students[0], 'behavior_id' => $this->b('phone_use')],
            ['student_id' => $this->students[1], 'role' => 'victim'],
        ]], $this->boss);
        $this->assertSame('open', $i->status);
        $this->assertStringStartsWith('DSP-', $i->incident_no);
        $this->assertSame([2, 0], $i->participants()->orderBy('id')->pluck('penalty_points')->all());

        $this->svc()->changeStatus($i, 'review', $this->boss);
        $this->svc()->changeStatus($i, 'closed', $this->boss, 'asılsız', 'unfounded');
        $this->assertSame('unfounded', $i->fresh()->outcome);
        $this->assertSame([], app(\App\Services\Discipline\DisciplineStanding::class)->forStudents([$this->students[0]]), 'asılsız olay puana sayılmaz');

        $this->expectException(BusinessRuleException::class);
        $this->svc()->changeStatus($i->fresh(), 'decided', $this->boss);
    }

    public function test_mixed_positive_and_negative_rejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->svc()->createIncident(['occurred_at' => '2026-09-10 10:00:00', 'students' => [
            ['student_id' => $this->students[0], 'behavior_id' => $this->b('phone_use')],
            ['student_id' => $this->students[1], 'behavior_id' => $this->b('appreciation')],
        ]], $this->boss);
    }

    public function test_counselor_cannot_decide_and_board_flow_requires_defense(): void
    {
        $i = $this->svc()->createIncident(['occurred_at' => '2026-09-10 10:00:00', 'students' => [['student_id' => $this->students[0], 'behavior_id' => $this->b('fight')]]], $this->boss);
        try {
            $this->svc()->decideSanction($i, ['student_id' => $this->students[0], 'sanction_type_id' => $this->t('written_warning')], $this->counselor);
            $this->fail('Rehber yaptırım veremez');
        } catch (BusinessRuleException $e) {
            $this->assertSame(403, $e->status);
        }

        $s = $this->svc()->decideSanction($i, ['student_id' => $this->students[0], 'sanction_type_id' => $this->t('suspension'), 'starts_on' => now()->toDateString(), 'days' => 2], $this->boss);
        $this->assertSame('proposed', $s->status, 'uzaklaştırma kurul kararı ister');
        $this->assertSame([], app(SuspensionCalendar::class)->onDate([$this->students[0]], now()->toDateString()), 'öneri yoklamaya işlenmez');

        $board = app(DisciplineBoardService::class);
        $m = $board->create(['title' => 'Kurul', 'scheduled_at' => now()->subHour()->toDateTimeString(), 'members' => [
            ['user_id' => $this->boss->id, 'role' => 'chair'], ['user_id' => $this->counselor->id],
        ], 'incident_ids' => [$i->id]], $this->boss);
        $item = $m->items()->first();
        $this->assertSame($s->id, $item->sanction_id);

        try {
            $board->decideItem($item, ['result' => 'accepted', 'votes_for' => 2, 'votes_against' => 0], $this->boss);
            $this->fail('Savunma alınmadan kabul edilmemeli');
        } catch (BusinessRuleException $e) {
            $this->assertSame('discipline_defense_missing', $e->errorCode);
        }
        try {
            $board->decideItem($item, ['result' => 'accepted', 'votes_for' => 3, 'votes_against' => 0], $this->boss);
            $this->fail('Oy sayısı üye sayısını aşamaz');
        } catch (BusinessRuleException $e) {
            $this->assertSame('discipline_votes_exceed', $e->errorCode);
        }

        $d = $this->svc()->requestDefense($i, $this->students[0], null, null, $this->counselor);
        $this->assertSame('review', $i->fresh()->status);
        $this->svc()->recordDefense($d, 'Savunmam budur.', $this->counselor);
        $board->decideItem($item->fresh(), ['result' => 'accepted', 'votes_for' => 2, 'votes_against' => 0], $this->boss);

        $s->refresh();
        $this->assertSame('active', $s->status);
        $this->assertSame('decided', $i->fresh()->status);
        $this->assertSame('held', $m->fresh()->status);
        $this->assertArrayHasKey($this->students[0], app(SuspensionCalendar::class)->onDate([$this->students[0], $this->students[1]], now()->toDateString()));

        // Aynı günlere ikinci uzaklaştırma çakışır
        $i2 = $this->svc()->createIncident(['occurred_at' => now()->subMinutes(5)->toDateTimeString(), 'students' => [['student_id' => $this->students[0], 'behavior_id' => $this->b('bullying')]]], $this->boss);
        try {
            $this->svc()->decideSanction($i2, ['student_id' => $this->students[0], 'sanction_type_id' => $this->t('suspension'), 'starts_on' => now()->addDay()->toDateString(), 'days' => 3], $this->boss);
            $this->fail('Çakışan uzaklaştırma engellenmeli');
        } catch (BusinessRuleException $e) {
            $this->assertSame('discipline_suspension_overlap', $e->errorCode);
        }
        $ok = $this->svc()->decideSanction($i2, ['student_id' => $this->students[0], 'sanction_type_id' => $this->t('suspension'), 'starts_on' => now()->addDays(2)->toDateString(), 'days' => 1], $this->boss);
        $this->assertSame('proposed', $ok->status);

        // Süre bitince tamamlanır, düşme tarihi geçince düşer
        $r = $this->svc()->sweep(\Carbon\CarbonImmutable::today()->addDays(3));
        $this->assertSame(1, $r['completed']);
        $r = $this->svc()->sweep(\Carbon\CarbonImmutable::today()->addDays(400));
        $this->assertSame(1, $r['expired']);
    }

    public function test_appeal_by_level(): void
    {
        $i = $this->svc()->createIncident(['occurred_at' => '2026-09-10 10:00:00', 'students' => [['student_id' => $this->students[2], 'behavior_id' => $this->b('phone_repeat')]]], $this->boss);
        $s = $this->svc()->decideSanction($i, ['student_id' => $this->students[2], 'sanction_type_id' => $this->t('written_warning')], $this->boss);
        $a = $this->svc()->fileAppeal($s, ['appellant' => 'guardian', 'reason' => 'İtiraz'], $this->counselor);
        $this->assertSame('appealed', $s->fresh()->status);
        $this->assertSame('appealed', $i->fresh()->status);
        try {
            $this->svc()->decideAppeal($a, ['status' => 'accepted'], $this->counselor);
            $this->fail('Rehber itirazı karara bağlayamaz');
        } catch (BusinessRuleException $e) {
            $this->assertSame(403, $e->status);
        }
        $this->svc()->decideAppeal($a, ['status' => 'modified', 'new_sanction_type_id' => $this->t('verbal_warning')], $this->boss);
        $s->refresh();
        $this->assertSame('active', $s->status);
        $this->assertSame($this->t('verbal_warning'), (int) $s->sanction_type_id);
        $this->assertSame('decided', $i->fresh()->status);
    }

    private function portal(User $user, Student $student): array
    {
        $req = Request::create('/api/v1/portal/discipline');
        $req->setUserResolver(fn () => $user);
        $req->attributes->set(EnsurePortalStudent::ATTRIBUTE, $student);

        return app(DisciplinePortalController::class)->index($req)->getData(true)['data'];
    }

    public function test_portal_shows_only_decided_visible_sanctions_and_student_can_submit(): void
    {
        $sid = $this->students[3];
        $student = Student::query()->find($sid);
        $account = new User;
        $account->forceFill(['branch_id' => 1, 'name' => 'Öğr', 'username' => 'o903', 'user_type' => 'student', 'password' => 'x'])->save();
        $guardian = new User;
        $guardian->forceFill(['branch_id' => 1, 'name' => 'Veli', 'username' => '05320000000', 'user_type' => 'guardian', 'password' => 'x'])->save();

        $i = $this->svc()->createIncident(['occurred_at' => '2026-09-11 10:00:00', 'description' => 'GİZLİ AYRINTI', 'witnesses' => 'Tanık X',
            'students' => [['student_id' => $sid, 'behavior_id' => $this->b('insult')], ['student_id' => $this->students[2], 'behavior_id' => $this->b('insult')]]], $this->boss);
        $this->svc()->decideSanction($i, ['student_id' => $sid, 'sanction_type_id' => $this->t('written_warning'), 'decision_note' => 'İÇ NOT'], $this->boss);
        $this->svc()->decideSanction($i, ['student_id' => $sid, 'sanction_type_id' => $this->t('guardian_meeting'), 'visible_to_portal' => false], $this->boss);
        $this->svc()->decideSanction($i, ['student_id' => $sid, 'sanction_type_id' => $this->t('reprimand')], $this->boss); // öneri
        $cancel = $this->svc()->decideSanction($i, ['student_id' => $sid, 'sanction_type_id' => $this->t('verbal_warning')], $this->boss);
        $this->svc()->changeSanctionStatus($cancel, 'cancelled', $this->boss, 'hata');
        $this->svc()->decideSanction($i, ['student_id' => $this->students[2], 'sanction_type_id' => $this->t('written_warning')], $this->boss); // başka öğrenci
        $d = $this->svc()->requestDefense($i, $sid, null, 'Yazınız', $this->boss);

        $data = $this->portal($account, $student);
        $this->assertTrue($data['enabled']);
        $this->assertSame(['Yazılı uyarı'], array_column($data['sanctions'], 'type'));
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        foreach (['GİZLİ AYRINTI', 'Tanık X', 'İÇ NOT', 'penalty'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertCount(1, $data['defenses']);
        $this->assertTrue($data['defenses'][0]['can_submit']);

        // Veli görür ama yazamaz
        $gd = $this->portal($guardian, $student);
        $this->assertFalse($gd['defenses'][0]['can_submit']);
        $req = Request::create('/x', 'POST', ['statement' => str_repeat('a', 30)]);
        $req->setUserResolver(fn () => $guardian);
        $req->attributes->set(EnsurePortalStudent::ATTRIBUTE, $student);
        try {
            app(DisciplinePortalController::class)->submitDefense($req, $d->id, $this->svc());
            $this->fail('Veli savunma yazamaz');
        } catch (BusinessRuleException $e) {
            $this->assertSame(403, $e->status);
        }
        $req->setUserResolver(fn () => $account);
        $this->actingAs($account);
        app(DisciplinePortalController::class)->submitDefense($req, $d->id, $this->svc());
        $this->assertSame('portal', $d->fresh()->submitted_via);
        $this->actingAs($this->boss);

        // Kurum ayarı kapalıysa hiçbir şey dönmez
        DisciplineSettings::put(['portal_enabled' => false], 1);
        $off = $this->portal($account, $student);
        $this->assertFalse($off['enabled']);
        $this->assertSame([], $off['sanctions']);
    }

    public function test_report_totals(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-01 12:00:00'));
        $i1 = $this->svc()->createIncident(['occurred_at' => '2026-09-05 10:00:00', 'students' => [
            ['student_id' => $this->students[0], 'behavior_id' => $this->b('fight')],
            ['student_id' => $this->students[2], 'behavior_id' => $this->b('fight')],
            ['student_id' => $this->students[1], 'role' => 'witness'],
        ]], $this->boss);
        $this->svc()->decideSanction($i1, ['student_id' => $this->students[0], 'sanction_type_id' => $this->t('written_warning')], $this->boss);
        $this->svc()->decideSanction($i1, ['student_id' => $this->students[2], 'sanction_type_id' => $this->t('reprimand')], $this->boss); // öneri: sayılmaz
        $i2 = $this->svc()->createIncident(['occurred_at' => '2026-10-02 10:00:00', 'students' => [['student_id' => $this->students[0], 'behavior_id' => $this->b('phone_use')]]], $this->boss);
        $this->svc()->createIncident(['occurred_at' => '2026-10-03 10:00:00', 'students' => [['student_id' => $this->students[3], 'behavior_id' => $this->b('thanks')]]], $this->boss);
        $gone = $this->svc()->createIncident(['occurred_at' => '2026-10-04 10:00:00', 'students' => [['student_id' => $this->students[3], 'behavior_id' => $this->b('smoking')]]], $this->boss);
        $this->svc()->deleteIncident($gone, $this->boss, 'yanlış kayıt');
        $this->svc()->createIncident(['occurred_at' => '2026-08-20 10:00:00', 'students' => [['student_id' => $this->students[0], 'behavior_id' => $this->b('smoking')]]], $this->boss); // aralık dışı

        $r = app(DisciplineReportService::class)->report(['from' => '2026-09-01', 'to' => '2026-10-31']);
        $t = $r['totals'];
        $this->assertSame(2, $t['incidents']);
        $this->assertSame(1, $t['positives']);
        $this->assertSame(2, $t['students']);
        $this->assertSame(12 + 12 + 2, $t['penalty']);
        $this->assertSame(5, $t['merit']);
        $this->assertSame(1, $t['sanctions']);
        $this->assertSame(1, $t['repeaters']);
        $this->assertSame($this->students[0], $r['repeaters'][0]['student_id']);
        $this->assertSame(14, $r['repeaters'][0]['net']);
        $this->assertSame(['Eylül 26', 'Ekim 26'], array_column($r['trend'], 'label'));
        $this->assertSame([1, 1], array_column($r['trend'], 'incidents'));
        $this->assertSame(2, collect($r['by_behavior'])->firstWhere('name', 'Kavga etme')['count']);
        $this->assertSame(1, collect($r['by_status'])->firstWhere('key', 'decided')['count']);

        $byClass = collect($r['by_class'])->keyBy('name');
        $this->assertSame(26 - 12, $byClass['10-A']['penalty']);
        $this->assertSame(12, $byClass['10-B']['penalty']);
        $this->assertSame(1, $byClass['10-B']['positives']);

        $only10A = app(DisciplineReportService::class)->report(['from' => '2026-09-01', 'to' => '2026-10-31', 'class_group_id' => 1]);
        $this->assertSame(2, $only10A['totals']['incidents']);
        $this->assertSame(1, $only10A['totals']['students']);
        $this->assertSame(0, $only10A['totals']['positives']);
    }
}
