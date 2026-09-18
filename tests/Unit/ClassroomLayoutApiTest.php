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
 * 3D derslik tasarımı API'si: yetki (okuma academic.view, yazma classroom_layouts.manage), örnek kaydın
 * yalnız bir kez oluşması, kaydet → sürüm, sürüm çakışması, geri yükleme, küçük görsel, öğrenci listesi (UUID),
 * ad maskeleme, doğrulama ve eşitleme kaydı. Bellek içi SQLite + gerçek migration'lar.
 */
class ClassroomLayoutApiTest extends TestCase
{
    private Branch $branch;

    private User $admin;

    private User $viewer;

    private User $outsider;

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
        Role::findOrCreate('izleyici', 'web')->syncPermissions(['academic.view']);
        Role::findOrCreate('muhasebe', 'web')->syncPermissions(['finance.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = $this->user('mudur1', 'yonetici');
        $this->viewer = $this->user('izleyici1', 'izleyici');
        $this->outsider = $this->user('muhasebe1', 'muhasebe');
    }

    private function user(string $username, string $role): User
    {
        $u = User::query()->create(['branch_id' => $this->branch->id, 'name' => ucfirst($username), 'username' => $username,
            'user_type' => 'staff', 'password' => 'Parola123!', 'is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

    private function payload(array $over = []): array
    {
        $data = SampleLayout::data();
        $data['objects'][2]['seats'] = [['uuid' => (string) Str::uuid(), 'name' => 'Ali Yılmaz']];

        return array_replace(['name' => 'ZZTEST 9-A dersliği', 'classroom_id' => null, 'data' => $data,
            'stats' => ['area' => 41.76, 'desks' => 20, 'capacity' => 20, 'assigned' => 1]], $over);
    }

    public function test_permission_is_in_catalog_and_granted_to_manager_roles(): void
    {
        $this->assertContains('classroom_layouts.manage', Permissions::all());
        $this->assertContains('classroom_layouts.manage', Permissions::defaultRoles()['mudur']['permissions']);
        $this->assertContains('classroom_layouts.manage', Permissions::defaultRoles()['yonetici']['permissions']);
        $this->assertNotContains('classroom_layouts.manage', Permissions::defaultRoles()['danisman']['permissions']);
    }

    public function test_tables_are_registered_for_two_way_sync_with_uuid(): void
    {
        foreach (['classroom_layouts', 'classroom_layout_versions'] as $t) {
            $def = SyncRegistry::get($t);
            $this->assertNotNull($def, "$t kayıt defterinde yok");
            $this->assertTrue($def->hasUuid());
            $this->assertTrue($def->isPushable(), "$t iki yönlü olmalı");
        }
    }

    public function test_sample_is_created_once_for_manager_and_never_comes_back_after_delete(): void
    {
        Sanctum::actingAs($this->viewer);
        $this->getJson('/api/v1/classroom-layouts')->assertOk()->assertJsonCount(0, 'data');   // yazma yetkisi yok → örnek oluşmaz

        Sanctum::actingAs($this->admin);
        $r = $this->getJson('/api/v1/classroom-layouts')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(SampleLayout::NAME, $r->json('data.0.name'));
        $this->assertTrue($r->json('data.0.is_demo'));
        $this->assertSame(20, $r->json('data.0.stats.desks'));
        $this->getJson('/api/v1/classroom-layouts')->assertJsonCount(1, 'data');                 // ikinci çağrı yeni kayıt açmaz

        $id = $r->json('data.0.id');
        $show = $this->getJson("/api/v1/classroom-layouts/$id")->assertOk();
        $this->assertCount(22, $show->json('data.data.objects'));
        $this->assertSame(1, collect($show->json('data.data.openings'))->where('kind', 'door')->count());
        $this->assertSame(3, collect($show->json('data.data.openings'))->where('kind', 'window')->count());

        $this->deleteJson("/api/v1/classroom-layouts/$id")->assertOk();
        $this->getJson('/api/v1/classroom-layouts')->assertOk()->assertJsonCount(0, 'data');     // silinen örnek geri gelmez
    }

    public function test_viewer_can_read_but_not_write_and_outsider_cannot_read(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/classroom-layouts', $this->payload())->assertCreated()->json('id');

        Sanctum::actingAs($this->viewer);
        $this->getJson("/api/v1/classroom-layouts/$id")->assertOk();
        $this->postJson('/api/v1/classroom-layouts', $this->payload())->assertForbidden();
        $this->putJson("/api/v1/classroom-layouts/$id", $this->payload())->assertForbidden();
        $this->deleteJson("/api/v1/classroom-layouts/$id")->assertForbidden();
        $this->postJson("/api/v1/classroom-layouts/$id/versions/1/restore")->assertForbidden();

        Sanctum::actingAs($this->outsider);
        $this->getJson('/api/v1/classroom-layouts')->assertForbidden();
        $this->getJson("/api/v1/classroom-layouts/$id")->assertForbidden();
    }

    public function test_student_names_are_masked_without_students_view(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/classroom-layouts', $this->payload())->assertCreated()->json('id');
        $this->assertSame('Ali Yılmaz', $this->getJson("/api/v1/classroom-layouts/$id")->json('data.data.objects.2.seats.0.name'));

        Sanctum::actingAs($this->viewer);   // academic.view var, students.view yok
        $seat = $this->getJson("/api/v1/classroom-layouts/$id")->json('data.data.objects.2.seats.0');
        $this->assertSame('Öğrenci', $seat['name']);
        $this->assertNotEmpty($seat['uuid'], 'yerleşim (UUID) korunur');
    }

    public function test_save_creates_versions_detects_conflict_and_restores(): void
    {
        Sanctum::actingAs($this->admin);
        $room = Classroom::query()->create(['name' => 'Derslik A', 'kind' => 'classroom', 'capacity' => 24, 'floor' => 'Zemin', 'is_active' => true]);
        $id = $this->postJson('/api/v1/classroom-layouts', $this->payload(['classroom_id' => $room->id]))->assertCreated()->json('id');

        $p = $this->payload(['base_version' => 1]);
        unset($p['classroom_id']);
        $p['data']['objects'] = array_slice($p['data']['objects'], 0, 5);
        $p['stats']['desks'] = 3;
        $this->putJson("/api/v1/classroom-layouts/$id", $p)->assertOk()->assertJsonPath('version', 2);
        $layout = ClassroomLayout::query()->findOrFail($id);
        $this->assertSame($room->id, $layout->classroom_id, 'classroom_id gönderilmezse bağ korunur');
        $this->assertCount(5, $layout->data['objects']);

        // Eski sürüm üzerinden kaydetme → 409
        $this->putJson("/api/v1/classroom-layouts/$id", $this->payload(['base_version' => 1]))->assertStatus(409)
            ->assertJsonPath('error_code', 'layout_version_conflict');

        $versions = $this->getJson("/api/v1/classroom-layouts/$id/versions")->assertOk()->json('data');
        $this->assertSame([2, 1], array_column($versions, 'version'));
        $this->assertTrue($versions[0]['is_current']);

        $this->postJson("/api/v1/classroom-layouts/$id/versions/1/restore")->assertOk()->assertJsonPath('version', 3);
        $layout->refresh();
        $this->assertCount(22, $layout->data['objects'], 'V1 içeriği geri geldi');
        $this->assertSame('V1 geri yüklendi', DB::table('classroom_layout_versions')->where('classroom_layout_id', $id)->where('version', 3)->value('label'));
        $this->postJson("/api/v1/classroom-layouts/$id/versions/99/restore")->assertNotFound();
    }

    public function test_validation_rejects_bad_geometry_and_foreign_classroom(): void
    {
        Sanctum::actingAs($this->admin);
        $bad = $this->payload();
        $bad['data']['room']['polygon'] = [['x' => 0, 'z' => 0], ['x' => 1, 'z' => 0]];
        $this->postJson('/api/v1/classroom-layouts', $bad)->assertStatus(422)->assertJsonValidationErrors(['data.room.polygon']);

        $bad = $this->payload();
        $bad['data']['room']['ceiling'] = 20;
        $this->postJson('/api/v1/classroom-layouts', $bad)->assertStatus(422)->assertJsonValidationErrors(['data.room.ceiling']);

        $other = Branch::query()->create(['code' => 'TASOVA', 'name' => 'Taşova']);
        $foreignId = DB::table('classrooms')->insertGetId(['branch_id' => $other->id, 'name' => 'Başka şube', 'kind' => 'classroom', 'capacity' => 10,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/classroom-layouts', $this->payload(['classroom_id' => $foreignId]))->assertStatus(422)->assertJsonValidationErrors(['classroom_id']);

        $this->postJson('/api/v1/classroom-layouts', $this->payload(['thumbnail' => 'javascript:alert(1)']))->assertStatus(422)->assertJsonValidationErrors(['thumbnail']);
    }

    public function test_thumbnail_roundtrip(): void
    {
        Sanctum::actingAs($this->admin);
        $png = 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $id = $this->postJson('/api/v1/classroom-layouts', $this->payload())->assertCreated()->json('id');
        $this->getJson("/api/v1/classroom-layouts/$id/thumbnail")->assertNotFound();
        $this->putJson("/api/v1/classroom-layouts/$id/thumbnail", ['thumbnail' => $png])->assertOk();
        $r = $this->get("/api/v1/classroom-layouts/$id/thumbnail")->assertOk();
        $this->assertSame('image/png', $r->headers->get('Content-Type'));
        $this->assertNotNull($this->getJson('/api/v1/classroom-layouts')->json('data.0.thumbnail_url'));
    }

    public function test_roster_lists_groups_using_the_classroom_and_students_by_uuid(): void
    {
        Sanctum::actingAs($this->admin);
        $room = Classroom::query()->create(['name' => 'Derslik B', 'kind' => 'classroom', 'capacity' => 20, 'is_active' => true]);
        $now = now();
        $term = DB::table('academic_terms')->insertGetId(['branch_id' => $this->branch->id, 'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true, 'created_at' => $now, 'updated_at' => $now]);
        $prog = DB::table('programs')->insertGetId(['branch_id' => $this->branch->id, 'code' => 'TYT', 'name' => 'TYT', 'kind' => 'group', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $g1 = DB::table('class_groups')->insertGetId(['branch_id' => $this->branch->id, 'academic_term_id' => $term, 'program_id' => $prog, 'name' => '9-A', 'is_active' => true, 'homeroom_classroom_id' => $room->id, 'created_at' => $now, 'updated_at' => $now]);
        $g2 = DB::table('class_groups')->insertGetId(['branch_id' => $this->branch->id, 'academic_term_id' => $term, 'program_id' => $prog, 'name' => '9-B', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $uuids = [];
        foreach ([['Zeynep', 'Ak', $g1, 'active'], ['Ahmet', 'Kara', $g1, 'active'], ['Mehmet', 'Su', $g1, 'withdrawn'], ['Can', 'Er', $g2, 'active']] as $i => [$f, $l, $g, $st]) {
            $uuid = (string) Str::uuid();
            $sid = DB::table('students')->insertGetId(['uuid' => $uuid, 'branch_id' => $this->branch->id, 'student_no' => (string) (100 + $i), 'first_name' => $f,
                'last_name' => $l, 'full_name' => "$f $l", 'status' => $st, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('class_group_student')->insert(['class_group_id' => $g, 'student_id' => $sid, 'joined_on' => '2026-09-01', 'created_at' => $now, 'updated_at' => $now]);
            $uuids[$f] = $uuid;
        }

        $r = $this->getJson('/api/v1/classroom-layouts/roster?classroom_id='.$room->id)->assertOk();
        $this->assertSame([$g1], $r->json('using_group_ids'));
        $this->assertSame([$g1], $r->json('selected_group_ids'));
        $this->assertSame(['Ahmet Kara', 'Zeynep Ak'], array_column($r->json('students'), 'name'), 'ayrılan öğrenci yok, ada göre sıralı');
        $this->assertSame($uuids['Zeynep'], collect($r->json('students'))->firstWhere('name', 'Zeynep Ak')['uuid']);

        $r = $this->getJson('/api/v1/classroom-layouts/roster?classroom_id='.$room->id.'&group_ids[]='.$g1.'&group_ids[]='.$g2)->assertOk();
        $this->assertCount(3, $r->json('students'));

        Sanctum::actingAs($this->viewer);
        $r = $this->getJson('/api/v1/classroom-layouts/roster?classroom_id='.$room->id)->assertOk();
        $this->assertSame([], $r->json('students'), 'students.view olmadan ad listesi dönmez');
        $this->assertFalse($r->json('can_view_students'));
    }
}
