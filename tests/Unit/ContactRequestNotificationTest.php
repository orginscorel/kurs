<?php

namespace Tests\Unit;

use App\Models\ContactRequest;
use App\Services\Communication\AnnouncementService;
use App\Services\Portal\ContactRequestNotifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Veli → öğretmen talebi: öğretmene ve yanıtta veliye YALNIZ uygulama içi bildirim;
 * SMS/WhatsApp kuyruğuna hiçbir şey yazılmaz. Sınıf/program duyurusunun veli görünürlüğü kuralı.
 */
class ContactRequestNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }

        Schema::create('teachers', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('branch_id')->nullable(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('first_name'), $t->string('last_name'), $t->timestamps(), $t->softDeletes()]);
        Schema::create('students', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('branch_id'), $t->string('full_name'), $t->timestamps(), $t->softDeletes()]);
        Schema::create('app_notifications', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('user_id'), $t->string('type', 20), $t->string('title'), $t->text('body')->nullable(), $t->string('action_url')->nullable(), $t->json('data')->nullable(), $t->timestamp('read_at')->nullable(), $t->timestamp('created_at')->nullable()]);
        Schema::create('outbound_messages', fn (Blueprint $t) => [$t->id(), $t->string('body')]);
        Schema::create('contact_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('guardian_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('teacher_id');
            $t->string('kind');
            $t->string('subject');
            $t->text('body');
            $t->string('preferred_times')->nullable();
            $t->string('status')->default('open');
            $t->text('response')->nullable();
            $t->unsignedBigInteger('responded_by')->nullable();
            $t->timestamp('responded_at')->nullable();
            $t->timestamps();
        });

        DB::table('teachers')->insert([['id' => 1, 'branch_id' => 1, 'user_id' => 500, 'first_name' => 'Murat', 'last_name' => 'Özer'], ['id' => 2, 'branch_id' => 1, 'user_id' => null, 'first_name' => 'Hesapsız', 'last_name' => 'Öğretmen']]);
        DB::table('students')->insert(['id' => 10, 'branch_id' => 1, 'full_name' => 'Ali Veli']);
        Queue::fake();
    }

    private function request(int $teacherId = 1): ContactRequest
    {
        $r = new ContactRequest;
        $r->forceFill(['branch_id' => 1, 'student_id' => 10, 'guardian_id' => 3, 'user_id' => 700, 'teacher_id' => $teacherId, 'kind' => 'meeting',
            'subject' => 'Matematik durumu', 'body' => 'Görüşmek istiyorum.', 'status' => 'open'])->save();

        return $r;
    }

    public function test_new_request_notifies_teacher_in_app_only_once(): void
    {
        $row = $this->request();
        $notifier = app(ContactRequestNotifier::class);

        $this->assertSame(1, $notifier->created($row));
        $this->assertSame(0, $notifier->created($row), 'aynı talep için ikinci bildirim yazılmamalı');

        $n = DB::table('app_notifications')->first();
        $this->assertSame(500, (int) $n->user_id);
        $this->assertSame('contact_request', $n->type);
        $this->assertSame('/ogretmen/talepler', $n->action_url);
        $this->assertStringContainsString('Görüşme talebi', $n->title);
        $this->assertStringContainsString('Ali Veli', $n->body);
        $this->assertSame(0, DB::table('outbound_messages')->count(), 'SMS/WhatsApp kuyruğuna yazılmamalı');
    }

    public function test_teacher_without_account_gets_no_notification_and_request_is_unaffected(): void
    {
        $row = $this->request(2);
        $this->assertSame(0, app(ContactRequestNotifier::class)->created($row));
        $this->assertSame(0, DB::table('app_notifications')->count());
        $this->assertTrue(DB::table('contact_requests')->where('id', $row->id)->exists());
    }

    public function test_answer_notifies_guardian_account(): void
    {
        $row = $this->request();
        $row->forceFill(['status' => 'answered', 'response' => 'Cuma 17:00 uygun.', 'responded_by' => 500, 'responded_at' => now()])->save();

        $this->assertSame(1, app(ContactRequestNotifier::class)->responded($row, 'Murat Özer'));

        $n = DB::table('app_notifications')->where('user_id', 700)->first();
        $this->assertSame('contact_answer', $n->type);
        $this->assertSame('Murat Özer talebinizi yanıtladı', $n->title);
        $this->assertSame('Cuma 17:00 uygun.', $n->body);
        $this->assertSame('/portal/ogretmenler', $n->action_url);

        // Kapatma ayrı bildirim olur
        $row->forceFill(['status' => 'closed', 'responded_at' => now()->addMinute()])->save();
        $this->assertSame(1, app(ContactRequestNotifier::class)->responded($row, 'Murat Özer'));
        $this->assertSame(2, DB::table('app_notifications')->where('user_id', 700)->count());
        $this->assertSame(0, DB::table('outbound_messages')->count());
    }

    public function test_notification_failure_never_breaks_the_request(): void
    {
        $row = $this->request();
        Schema::drop('app_notifications');
        $this->assertSame(0, app(ContactRequestNotifier::class)->created($row));
    }

    public function test_class_and_program_announcements_are_visible_to_guardians_unless_students_only(): void
    {
        $this->assertTrue(AnnouncementService::visibleToGuardians(['type' => 'class_group', 'id' => 3]));
        $this->assertTrue(AnnouncementService::visibleToGuardians(['type' => 'program', 'id' => 1, 'students_only' => false]));
        $this->assertFalse(AnnouncementService::visibleToGuardians(['type' => 'class_group', 'id' => 3, 'students_only' => true]));
        $this->assertFalse(AnnouncementService::visibleToGuardians(['type' => 'all_students']));
        $this->assertFalse(AnnouncementService::visibleToGuardians(['type' => 'teachers']));
    }
}
