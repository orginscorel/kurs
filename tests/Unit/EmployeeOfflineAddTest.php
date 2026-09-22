<?php
namespace Tests\Unit;
use App\Models\Branch; use App\Models\User; use App\Support\Permissions; use App\Support\BranchContext;
use Laravel\Sanctum\Sanctum; use Spatie\Permission\Models\Permission; use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar; use Tests\TestCase;

class EmployeeOfflineAddTest extends TestCase
{
    public function test_add_employee_with_empty_optional_position_succeeds(): void
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $b = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']); app(BranchContext::class)->set($b->id);
        foreach (Permissions::all() as $p) Permission::findOrCreate($p, 'web');
        Role::findOrCreate('yonetici', 'web')->syncPermissions(Permissions::all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $u = User::query()->create(['branch_id' => $b->id, 'name' => 'M', 'username' => 'm1', 'user_type' => 'staff', 'password' => 'x', 'is_active' => true]);
        $u->assignRole('yonetici'); Sanctum::actingAs($u);
        config(['kurs.node' => 'local']); // çevrimdışı yerel düğüm gibi

        // Personel formunun minimal gönderimi: yalnız ad/soyad, pozisyon ve diğer opsiyoneller boş.
        $res = $this->postJson('/api/v1/employees', [
            'first_name' => 'Boş', 'last_name' => 'Pozisyon', 'position' => '', 'phone' => '', 'email' => '', 'hired_on' => '',
        ]);
        $res->assertStatus(201);
        $this->assertDatabaseHas('employees', ['first_name' => 'Boş', 'last_name' => 'Pozisyon', 'position' => '']);
    }
}
