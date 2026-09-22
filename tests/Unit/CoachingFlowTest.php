<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\CoachingAssignment;
use App\Models\CoachingPlan;
use App\Models\CoachingPlanItem;
use App\Models\Student;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\Permissions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Koçluk (akademik koçluk) modülü: koç atama (tek aktif), görüşme kaydı (çevrimdışı boş metin),
 * haftalık plan + kalem işaretleme, izin ve şube izolasyonu.
 */
class CoachingFlowTest extends TestCase
{
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->run();

        $this->branch = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']);
        app(BranchContext::class)->set($this->branch->id);

        foreach (Permissions::all() as $p) {
            Permission::findOrCreate($p, 'web');
        }
        Role::findOrCreate('yonetici', 'web')->syncPermissions(Permissions::all());
        Role::findOrCreate('bosrol', 'web'); // yetkisiz rol
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function staff(string $username, string $role = 'yonetici', ?int $branchId = null): User
    {
        $u = User::query()->create([
            'branch_id' => $branchId ?? $this->branch->id, 'name' => strtoupper($username), 'username' => $username,
            'user_type' => 'staff', 'password' => 'x', 'is_active' => true,
        ]);
        $u->assignRole($role);

        return $u;
    }

    private function student(string $no = '2026001', ?int $branchId = null): Student
    {
        return Student::query()->create([
            'branch_id' => $branchId ?? $this->branch->id, 'student_no' => $no,
            'first_name' => 'Test', 'last_name' => 'Öğrenci '.$no, 'status' => 'active',
        ]);
    }

    public function test_assign_coach_keeps_single_active_assignment(): void
    {
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin);
        $student = $this->student();
        $coach1 = $this->staff('coach1');
        $coach2 = $this->staff('coach2');

        $this->postJson("/api/v1/coaching/students/{$student->id}/coach", ['coach_id' => $coach1->id])->assertOk();
        $this->postJson("/api/v1/coaching/students/{$student->id}/coach", ['coach_id' => $coach2->id])->assertOk();

        $active = CoachingAssignment::query()->where('student_id', $student->id)->where('is_active', true)->get();
        $this->assertCount(1, $active, 'Öğrencinin yalnız bir aktif koçu olmalı.');
        $this->assertSame($coach2->id, $active->first()->coach_id);
        $this->assertSame(2, CoachingAssignment::query()->where('student_id', $student->id)->count(), 'Tarihçe korunmalı.');
    }

    public function test_session_can_be_recorded_offline_with_empty_topics(): void
    {
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin);
        $student = $this->student();

        $res = $this->postJson('/api/v1/coaching/sessions', [
            'student_id' => $student->id, 'held_at' => now()->toDateTimeString(), 'topics' => '',
        ]);
        $res->assertStatus(201);
        $this->assertDatabaseHas('coaching_sessions', ['student_id' => $student->id, 'topics' => '']);
    }

    public function test_plan_with_items_and_item_toggle(): void
    {
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin);
        $student = $this->student();

        $res = $this->postJson('/api/v1/coaching/plans', [
            'student_id' => $student->id,
            'week_start' => '2026-09-21', // pazartesi
            'note' => 'Bu hafta matematik ağırlıklı',
            'items' => [
                ['subject' => 'Matematik', 'target_kind' => 'questions', 'target' => 200],
                ['subject' => 'Fizik', 'target_kind' => 'hours', 'target' => 6],
                ['subject' => '', 'target_kind' => 'questions', 'target' => null], // boş → atlanır
            ],
        ]);
        $res->assertStatus(201);

        $plan = CoachingPlan::query()->where('student_id', $student->id)->firstOrFail();
        $this->assertCount(2, $plan->items, 'Boş kalem elenmeli.');

        $item = $plan->items()->where('subject', 'Matematik')->firstOrFail();
        $this->postJson("/api/v1/coaching/plan-items/{$item->id}/toggle", ['is_done' => true])->assertOk();
        $this->assertTrue(CoachingPlanItem::query()->find($item->id)->is_done);

        // Aynı hafta tekrar kaydetmek yeni plan açmaz (updateOrCreate)
        $this->postJson('/api/v1/coaching/plans', ['student_id' => $student->id, 'week_start' => '2026-09-23', 'items' => []])->assertStatus(201);
        $this->assertSame(1, CoachingPlan::query()->where('student_id', $student->id)->count());
    }

    public function test_coaching_endpoints_require_permission(): void
    {
        $nobody = $this->staff('nobody', 'bosrol');
        Sanctum::actingAs($nobody);

        $this->getJson('/api/v1/coaching/dashboard')->assertStatus(403);
        $this->getJson('/api/v1/coaching/assignments')->assertStatus(403);
    }

    public function test_branch_isolation_hides_other_branch_sessions(): void
    {
        // A şubesi verisi
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin);
        $studentA = $this->student('A-1');
        $this->postJson('/api/v1/coaching/sessions', ['student_id' => $studentA->id, 'held_at' => now()->toDateTimeString(), 'topics' => 'A'])->assertStatus(201);

        // B şubesi + B kullanıcısı
        $branchB = Branch::query()->create(['code' => 'IKINCI', 'name' => 'İkinci Şube']);
        app(BranchContext::class)->set($branchB->id);
        $adminB = $this->staff('adminb', 'yonetici', $branchB->id);
        Sanctum::actingAs($adminB);

        $list = $this->getJson('/api/v1/coaching/sessions')->assertOk()->json('data');
        $this->assertCount(0, $list, 'B şubesi A şubesinin koçluk görüşmelerini görmemeli.');
    }
}
