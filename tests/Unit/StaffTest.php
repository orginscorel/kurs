<?php

namespace Tests\Unit;

use App\Models\Teacher;
use PHPUnit\Framework\TestCase;

class StaffTest extends TestCase
{
    public function test_teacher_full_name_trims_whitespace(): void
    {
        $t = new Teacher(['first_name' => 'Ayşe', 'last_name' => 'Demir']);
        $this->assertSame('Ayşe Demir', $t->full_name);
    }

    public function test_employment_types_catalog_has_expected_keys(): void
    {
        $this->assertSame(['full_time', 'part_time', 'hourly'], array_keys(Teacher::EMPLOYMENT_TYPES));
    }
}
