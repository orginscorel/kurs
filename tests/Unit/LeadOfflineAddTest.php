<?php
namespace Tests\Unit;
use App\Models\Branch; use App\Models\User; use App\Support\Permissions; use App\Support\BranchContext;
use Laravel\Sanctum\Sanctum; use Spatie\Permission\Models\Permission; use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar; use Tests\TestCase;
class LeadOfflineAddTest extends TestCase {
  public function test_quick_add_with_only_guardian_phone_and_no_source_succeeds(): void {
    $this->artisan('migrate',['--force'=>true])->run();
    $b=Branch::query()->create(['code'=>'ERBAA','name'=>'Merkez']); app(BranchContext::class)->set($b->id);
    foreach(Permissions::all() as $p) Permission::findOrCreate($p,'web');
    Role::findOrCreate('yonetici','web')->syncPermissions(Permissions::all());
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $u=User::query()->create(['branch_id'=>$b->id,'name'=>'M','username'=>'m1','user_type'=>'staff','password'=>'x','is_active'=>true]);
    $u->assignRole('yonetici'); Sanctum::actingAs($u);
    config(['kurs.node'=>'local']); // çevrimdışı yerel düğüm gibi
    // LeadDrawer'ın minimal gönderimi: yalnız veli telefonu, source YOK, boş stringler
    $res=$this->postJson('/api/v1/crm/leads',[
      'first_name'=>'Hızlı','last_name'=>'Aday','phone'=>'','guardian_name'=>'Veli A','guardian_phone'=>'05551112233',
      'email'=>'','school_name'=>'','interested_program_id'=>'','owner_id'=>'','next_action'=>'','next_action_at'=>'',
    ]);
    $res->assertStatus(201);
    $this->assertDatabaseHas('leads',['first_name'=>'Hızlı','phone'=>'','source'=>'other']);
  }
}
