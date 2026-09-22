<?php
namespace Tests\Unit;
use App\Models\Branch; use App\Models\Student; use App\Models\User; use App\Support\Permissions; use App\Support\BranchContext;
use Laravel\Sanctum\Sanctum; use Spatie\Permission\Models\Permission; use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar; use Tests\TestCase;

class GuidanceOfflineAddTest extends TestCase
{
    public function test_add_guidance_meeting_with_empty_summary_succeeds(): void
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $b = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']); app(BranchContext::class)->set($b->id);
        foreach (Permissions::all() as $p) Permission::findOrCreate($p, 'web');
        Role::findOrCreate('yonetici', 'web')->syncPermissions(Permissions::all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $u = User::query()->create(['branch_id' => $b->id, 'name' => 'M', 'username' => 'm1', 'user_type' => 'staff', 'password' => 'x', 'is_active' => true]);
        $u->assignRole('yonetici'); Sanctum::actingAs($u);
        config(['kurs.node' => 'local']); // çevrimdışı yerel düğüm gibi

        $student = Student::query()->create(['branch_id' => $b->id, 'student_no' => '2026001', 'first_name' => 'Test', 'last_name' => 'Öğrenci', 'status' => 'active']);

        // Rehberlik görüşmesinin minimal/hızlı kaydı: özet boş bırakıldı.
        $res = $this->postJson('/api/v1/guidance/meetings', [
            'student_id' => $student->id, 'met_at' => now()->toDateTimeString(), 'kind' => 'individual',
            'visibility' => 'staff', 'summary' => '', 'goal' => '', 'next_meeting_on' => '',
        ]);
        $res->assertStatus(201);
        $this->assertDatabaseHas('guidance_meetings', ['student_id' => $student->id, 'kind' => 'individual', 'summary' => '']);
    }
}
