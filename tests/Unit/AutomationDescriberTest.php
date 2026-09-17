<?php

namespace Tests\Unit;

use App\Services\Automation\AutomationDescriber;
use PHPUnit\Framework\TestCase;

class AutomationDescriberTest extends TestCase
{
    public function test_describes_overdue_installment_rule(): void
    {
        $description = AutomationDescriber::describe(
            'installment.overdue',
            ['days' => 3],
            [['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'payment.overdue']],
            0,
        );

        $this->assertSame('Taksit 3 gün gecikince velisine WhatsApp gönder.', $description);
    }

    public function test_describes_absence_rule_with_delay(): void
    {
        $description = AutomationDescriber::describe(
            'attendance.absent',
            null,
            [['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'guardian.absent']],
            15,
        );

        $this->assertSame('Öğrenci derse gelmediğinde 15 dakika sonra velisine WhatsApp gönder.', $description);
    }

    public function test_describes_multiple_actions_joined(): void
    {
        $description = AutomationDescriber::describe(
            'exam.result_published',
            null,
            [
                ['type' => 'whatsapp', 'to' => 'student', 'template' => 'exam.result.student'],
                ['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'exam.result.guardian'],
            ],
            0,
        );

        $this->assertSame('Sınav sonucu yayımlandığında öğrenciye WhatsApp gönder, ayrıca velisine WhatsApp gönder.', $description);
    }

    public function test_describes_upcoming_installment_with_days(): void
    {
        $description = AutomationDescriber::describe(
            'installment.upcoming',
            ['days' => 2],
            [['type' => 'sms', 'to' => 'guardian']],
            0,
        );

        $this->assertSame('Taksit vadesine 2 gün kala velisine SMS gönder.', $description);
    }
}
