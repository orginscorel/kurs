<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Classroom;
use App\Models\ClassroomLayout;
use App\Models\User;
use App\Services\ClassroomDesign\SampleLayout;
use App\Support\BranchContext;
use App\Support\Permissions;
use App\Sync\SyncRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sınıf oturma planı: oda düzeninden ayrı, sınıf başına; derslik çözümü (sınıf dersliği / ders programı),
 * eski (oda JSON'undaki) atamalardan geriye uyumlu okuma, doğrulama (yabancı öğrenci, çift oturma, kullanılamaz masa),
 * silinen masanın atamasının düşmesi, yetkiler, liste özeti. Bellek içi SQLite + gerçek migration'lar.
 */
class SeatingPlanApiTest extends TestCase
{
    private Branch $branch;

    private User $admin;

    private User $viewer;

    private int $room;

    private int $groupA;

    private int $groupB;

    /** @var array<string, string> ad → uuid */
    private array $uuid = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }
        config(['kurs.silent_events' => true, 'kurs.node' => 'server']);
        $this->artisan('migrate', ['--force' => true])->run();
        $this->branch = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']);
        app(BranchContext::class)->set($this->branch->id);
        foreach (Permissions::all() as $p) {
            Permission::findOrCreate($p, 'web');
        }
        Role::findOrCreate('yonetici', 'web')->syncPermissions(Permissions::all());
        Role::findOrCreate('izleyici', 'web')->syncPermissions(['academic.view', 'students.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = $this->user('mudur1', 'yonetici');
        $this->viewer = $this->user('izleyici1', 'izleyici');

        $now = now();
        $term = DB::table('academic_terms')->insertGetId(['branch_id' => $this->branch->id, 'name' => '2026', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true, 'created_at' => $now, 'updated_at' => $now]);
        $prog = DB::table('programs')->insertGetId(['branch_id' => $this->branch->id, 'code' => 'TYT', 'name' => 'TYT', 'kind' => 'group', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $this->room = Classroom::query()->create(['name' => 'Derslik A', 'kind' => 'classroom', 'capacity' => 24, 'is_active' => true])->id;
        $mk = fn ($name, $home) => DB::table('class_groups')->insertGetId(['branch_id' => $this->branch->id, 'academic_term_id' => $term, 'program_id' => $prog, 'name' => $name, 'is_active' => true, 'homeroom_classroom_id' => $home, 'created_at' => $now, 'updated_at' => $now]);
        $this->groupA = $mk('12-A', $this->room);
        $this->groupB = $mk('12-B', null);
        foreach ([['Zeynep', $this->groupA, 'female'], ['Ahmet', $this->groupA, 'male'], ['Can', $this->groupB, 'male']] as $i => [$n, $g, $gender]) {
            $u = (string) Str::uuid();
            $sid = DB::table('students')->insertGetId(['uuid' => $u, 'branch_id' => $this->branch->id, 'student_no' => (string) (100 + $i), 'first_name' => $n, 'last_name' => 'Test', 'full_name' => "$n Test", 'gender' => $gender, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('class_group_student')->insert(['class_group_id' => $g, 'student_id' => $sid, 'joined_on' => '2026-09-01', 'created_at' => $now, 'updated_at' => $now]);
            $this->uuid[$n] = $u;
        }
    }

    private function user(string $username, string $role): User
    {
        $u = User::query()->create(['branch_id' => $this->branch->id, 'name' => ucfirst($username), 'username' => $username, 'user_type' => 'staff', 'password' => 'Parola123!', 'is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

    private function layout(array $legacySeats = []): ClassroomLayout
    {
        $data = SampleLayout::data();
        foreach ($legacySeats as $idx => $seat) {
            $data['objects'][$idx]['seats'] = [$seat];
        }

        return ClassroomLayout::query()->create(['name' => 'Oda', 'classroom_id' => $this->room, 'version' => 1, 'is_active' => true, 'data' => $data, 'stats' => []]);
    }

    public function test_registered_for_sync(): void
    {
        $def = SyncRegistry::get('class_seating_plans');
        $this->assertNotNull($def);
        $this->assertTrue($def->hasUuid() && $def->isPushable());
    }

    public function test_show_resolves_homeroom_and_reads_legacy_assignments_of_this_group_only(): void
    {
        // Eski düzende masa 1'de Zeynep (12-A), masa 2'de Can (12-B)
        $this->layout([2 => ['uuid' => $this->uuid['Zeynep'], 'name' => 'Zeynep Test'], 3 => ['uuid' => $this->uuid['Can'], 'name' => 'Can Test']]);
        Sanctum::actingAs($this->viewer);
        $r = $this->getJson("/api/v1/class-groups/{$this->groupA}/seating")->assertOk();
        $this->assertSame($this->room, $r->json('classroom_id'));
        $this->assertTrue($r->json('classrooms.0.homeroom'));
        $this->assertCount(2, $r->json('students'));
        $this->assertTrue($r->json('has_gender'));
        $this->assertTrue($r->json('plan.legacy'));
        $this->assertSame([$this->uuid['Zeynep']], $r->json('plan.seats.o-desk-1'));
        $this->assertNull($r->json('plan.seats.o-desk-2'), '12-B öğrencisi 12-A planına taşınmaz');
        $this->assertNull($r->json('layout.data.objects.2.seats.0'), 'oda düzenindeki eski atamalar ekrana taşınmaz');
        $this->assertFalse($r->json('can_edit'));
    }

    public function test_save_validates_and_is_per_group(): void
    {
        $layout = $this->layout();
        Sanctum::actingAs($this->viewer);
        $this->putJson("/api/v1/class-groups/{$this->groupA}/seating", ['classroom_layout_id' => $layout->id, 'seats' => [], 'statuses' => []])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $url = "/api/v1/class-groups/{$this->groupA}/seating";
        $this->putJson($url, ['classroom_layout_id' => $layout->id, 'seats' => ['o-desk-1' => [$this->uuid['Can']]], 'statuses' => []])
            ->assertStatus(422)->assertJsonPath('error_code', 'seating_foreign_student');
        $this->putJson($url, ['classroom_layout_id' => $layout->id, 'seats' => ['o-desk-1' => [$this->uuid['Ahmet']], 'o-desk-2' => [$this->uuid['Ahmet']]], 'statuses' => []])
            ->assertStatus(422)->assertJsonPath('error_code', 'seating_duplicate_student');
        $this->putJson($url, ['classroom_layout_id' => $layout->id, 'seats' => ['o-desk-1' => [$this->uuid['Ahmet']]], 'statuses' => ['o-desk-1' => 'unavailable']])
            ->assertStatus(422)->assertJsonPath('error_code', 'seating_unavailable_desk');

        $this->putJson($url, ['classroom_layout_id' => $layout->id, 'seats' => ['o-desk-1' => [$this->uuid['Zeynep']], 'yok-masa' => [$this->uuid['Ahmet']]], 'statuses' => ['o-desk-5' => 'reserved', 'o-desk-6' => 'bozuk']])
            ->assertStatus(422);
        $this->putJson($url, ['classroom_layout_id' => $layout->id, 'seats' => ['o-desk-1' => [$this->uuid['Zeynep']], 'yok-masa' => [$this->uuid['Ahmet']]], 'statuses' => ['o-desk-5' => 'reserved']])->assertOk();

        $r = $this->getJson($url)->assertOk();
        $this->assertFalse($r->json('plan.legacy'));
        $this->assertSame([$this->uuid['Zeynep']], $r->json('plan.seats.o-desk-1'));
        $this->assertNull($r->json('plan.seats.yok-masa'), 'oda düzeninde olmayan masa kaydedilmez → öğrenci yerleştirilmemiş kalır');
        $this->assertSame('reserved', $r->json('plan.statuses.o-desk-5'));
        $this->assertTrue(ClassroomLayout::query()->whereKey($layout->id)->first()->data['objects'][2]['seats'] === [null], 'oda düzeni değişmez');

        // 12-B aynı odada kendi planı (dersliği ders programından)
        DB::table('lesson_schedules')->insert(['branch_id' => $this->branch->id, 'academic_term_id' => DB::table('academic_terms')->value('id'), 'class_group_id' => $this->groupB, 'classroom_id' => $this->room, 'subject_id' => DB::table('subjects')->insertGetId(['branch_id' => $this->branch->id, 'code' => 'MAT', 'name' => 'Matematik', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]),
            'teacher_id' => DB::table('teachers')->insertGetId(['branch_id' => $this->branch->id, 'first_name' => 'Ay', 'last_name' => 'Öğr', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]),
            'weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '09:40', 'valid_from' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()]);
        $b = $this->getJson("/api/v1/class-groups/{$this->groupB}/seating")->assertOk();
        $this->assertSame($this->room, $b->json('classroom_id'));
        $this->assertSame(1, $b->json('classrooms.0.weekly_lessons'));
        $this->assertNull($b->json('plan'));

        // Liste özeti
        $o = collect($this->getJson('/api/v1/class-seating/overview')->assertOk()->json('data'))->keyBy('group_id');
        $this->assertSame(1, $o[$this->groupA]['seated']);
        $this->assertSame($layout->id, $o[$this->groupB]['layout_id']);
    }

    public function test_group_without_classroom_or_layout(): void
    {
        Sanctum::actingAs($this->admin);
        $r = $this->getJson("/api/v1/class-groups/{$this->groupB}/seating")->assertOk();
        $this->assertSame([], $r->json('classrooms'));
        $this->assertNull($r->json('layout'));
        $r = $this->getJson("/api/v1/class-groups/{$this->groupA}/seating")->assertOk();
        $this->assertSame($this->room, $r->json('classroom_id'));
        $this->assertNull($r->json('layout'), 'dersliğin oda düzeni yok');
        $this->assertTrue($r->json('can_edit_room'));
    }
}
