<?php

namespace Tests\Unit;

use App\Exceptions\BusinessRuleException;
use App\Models\Enrollment;
use App\Models\Installment;
use App\Services\Finance\EnrollmentService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Öğrenci profilinden "paketi değiştir": ödenmemiş kayıtta paket/ücret değişir ve plan
 * yeniden kurulur; tahsilat yapılmış kayıtta ise engellenir (finans tutarlılığı).
 */
class EnrollmentChangePackageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(BranchContext::class)->set(1);
        $now = now();
        DB::table('branches')->insert(['id' => 1, 'code' => 'MRK', 'name' => 'Merkez', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('academic_terms')->insert(['id' => 1, 'branch_id' => 1, 'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'created_at' => $now, 'updated_at' => $now]);
        foreach ([1, 2] as $pid) {
            DB::table('programs')->insert(['id' => $pid, 'branch_id' => 1, 'code' => "P$pid", 'name' => "Program $pid", 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('students')->insert(['id' => 1, 'branch_id' => 1, 'student_no' => '9001', 'first_name' => 'Test', 'last_name' => 'Öğrenci', 'full_name' => 'Test Öğrenci', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    }

    private function makeEnrollment(): Enrollment
    {
        return Enrollment::query()->create([
            'branch_id' => 1, 'student_id' => 1, 'academic_term_id' => 1, 'program_id' => 1,
            'enrollment_no' => 'KYT-TEST-1', 'list_price' => '1000.00', 'discount_amount' => '0.00',
            'scholarship_amount' => '0.00', 'net_price' => '1000.00', 'status' => 'active', 'enrolled_on' => '2026-09-01',
        ]);
    }

    private function changeData(int $programId): array
    {
        return [
            'program_id' => $programId, 'education_package_id' => null, 'list_price' => '1200.00',
            'discount_amount' => '0.00', 'scholarship_amount' => '0.00', 'down_payment' => '0.00',
            'installment_count' => 3, 'first_due_date' => '2026-10-01',
        ];
    }

    public function test_change_package_rebuilds_plan_when_unpaid(): void
    {
        $enrollment = $this->makeEnrollment();
        Installment::query()->create(['branch_id' => 1, 'enrollment_id' => $enrollment->id, 'student_id' => 1, 'sequence' => 1, 'due_date' => '2026-10-01', 'amount' => '500.00', 'paid_amount' => '0.00']);
        Installment::query()->create(['branch_id' => 1, 'enrollment_id' => $enrollment->id, 'student_id' => 1, 'sequence' => 2, 'due_date' => '2026-11-01', 'amount' => '500.00', 'paid_amount' => '0.00']);

        app(EnrollmentService::class)->changePackage($enrollment, $this->changeData(2));

        $enrollment->refresh();
        $this->assertSame('1200.00', (string) $enrollment->net_price);
        $this->assertSame(2, (int) $enrollment->program_id);
        $this->assertSame(3, $enrollment->installments()->count());
        $this->assertEquals(1200.0, (float) $enrollment->installments()->sum('amount'));
    }

    public function test_change_package_blocked_when_payment_exists(): void
    {
        $enrollment = $this->makeEnrollment();
        Installment::query()->create(['branch_id' => 1, 'enrollment_id' => $enrollment->id, 'student_id' => 1, 'sequence' => 1, 'due_date' => '2026-10-01', 'amount' => '1000.00', 'paid_amount' => '250.00', 'status' => 'partial']);

        $this->expectException(BusinessRuleException::class);
        app(EnrollmentService::class)->changePackage($enrollment, $this->changeData(1));
    }
}
