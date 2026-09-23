<?php

namespace Tests\Unit;

use App\Jobs\SendOutboundMessage;
use App\Models\Branch;
use App\Models\Guardian;
use App\Models\Integration;
use App\Models\NotificationBatch;
use App\Models\OutboundMessage;
use App\Models\Student;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use App\Support\BranchContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Bildirim omurgası: olay×kitle taslak üretimi, onay/gönderim ve — en önemlisi — entegrasyon
 * yokken GERÇEK mesaj gönderilmemesi (simülasyon). Bellek içi SQLite (canlı DB'ye dokunmaz).
 */
class NotificationBackboneTest extends TestCase
{
    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }
        config(['kurs.silent_events' => true, 'kurs.node' => 'server']);
        Http::preventStrayRequests();

        $this->artisan('migrate', ['--force' => true])->run();

        $this->branch = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']);
        app(BranchContext::class)->set($this->branch->id);
        foreach (Permissions::all() as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $role = Role::findOrCreate('yonetici', 'web');
        $role->syncPermissions(Permissions::all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::query()->create([
            'branch_id' => $this->branch->id, 'name' => 'Müdür', 'username' => 'mudur',
            'user_type' => 'staff', 'password' => 'Parola123!', 'is_active' => true, 'phone' => '05320000001',
        ]);
        $this->admin->assignRole('yonetici');
        Auth::setUser($this->admin);
    }

    private function studentWithGuardian(): Student
    {
        $s = new Student;
        $s->forceFill(['branch_id' => $this->branch->id, 'student_no' => (string) random_int(100000, 999999),
            'first_name' => 'Ali', 'last_name' => 'Yılmaz', 'phone' => '05321112233', 'status' => 'active'])->save();
        $g = Guardian::query()->create(['branch_id' => $this->branch->id, 'first_name' => 'Veli', 'last_name' => 'Bey', 'phone' => '05320009988']);
        $s->guardians()->attach($g->id, ['is_primary' => true, 'receives_notifications' => true]);

        return $s->refresh();
    }

    private function service(): EventNotificationService
    {
        return app(EventNotificationService::class);
    }

    public function test_build_drafts_creates_batch_with_audience_messages(): void
    {
        $s = $this->studentWithGuardian();
        $batch = $this->service()->buildDrafts('coaching.session', [
            'student_ids' => [$s->id],
            'audiences' => ['student', 'parent', 'admin'],
            'vars' => ['koc_adi' => 'Ayşe Koç', 'tarih' => '25.09.2026', 'saat' => '15:00', 'konu' => 'Deneme analizi'],
        ], $this->admin->id);

        $this->assertSame('draft', $batch->status);
        $this->assertGreaterThanOrEqual(3, $batch->total); // öğrenci + veli + yönetici özeti

        $audiences = OutboundMessage::query()->where('batch_id', $batch->id)->pluck('audience')->unique()->sort()->values()->all();
        $this->assertEqualsCanonicalizing(['admin', 'parent', 'student'], $audiences);

        // Öğrenci mesajı katalog şablonuyla render edilmiş ({{koc_adi}} yerine değer).
        $studentMsg = OutboundMessage::query()->where('batch_id', $batch->id)->where('audience', 'student')->first();
        $this->assertStringContainsString('Ayşe Koç', $studentMsg->body);
        $this->assertSame('draft', $studentMsg->status);
        $this->assertStringContainsString('5321112233', $studentMsg->to);
    }

    public function test_disabled_event_blocks_drafts(): void
    {
        $s = $this->studentWithGuardian();
        $setting = $this->service()->setting($this->branch->id, 'payment.reminder');
        $setting->update(['enabled' => false]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service()->buildDrafts('payment.reminder', ['student_ids' => [$s->id]], $this->admin->id);
    }

    public function test_approve_without_integration_simulates_and_sends_nothing(): void
    {
        Bus::fake();
        $s = $this->studentWithGuardian();
        $batch = $this->service()->buildDrafts('attendance.mark', [
            'student_ids' => [$s->id], 'audiences' => ['parent'],
            'vars' => ['ders_adi' => 'Matematik', 'ders_saati' => '10:00', 'durum' => 'Geldi'],
        ], $this->admin->id);

        $approved = $this->service()->approve($batch, $this->admin->id);

        // Entegrasyon yok → gerçek gönderim işi HİÇ kuyruğa alınmaz; mesajlar simülasyon olarak işaretlenir.
        Bus::assertNotDispatched(SendOutboundMessage::class);
        $this->assertSame('sent', $approved->status);
        $msg = OutboundMessage::query()->where('batch_id', $batch->id)->first();
        $this->assertSame('sent', $msg->status);
        $this->assertSame('simulation', $msg->provider);
    }

    public function test_approve_with_connected_integration_queues_real_job(): void
    {
        Bus::fake();
        Integration::query()->create([
            'branch_id' => $this->branch->id, 'kind' => 'whatsapp', 'provider' => 'meta_cloud',
            'status' => 'connected', 'is_enabled' => true, 'config_encrypted' => encrypt(json_encode(['token' => 'x'])),
        ]);
        $s = $this->studentWithGuardian();
        $batch = $this->service()->buildDrafts('lesson.one_to_one', [
            'student_ids' => [$s->id], 'audiences' => ['parent'],
            'vars' => ['ogretmen_adi' => 'Mehmet Hoca', 'ders_adi' => 'Fizik', 'tarih' => '26.09.2026', 'saat' => '14:00', 'derslik' => 'A-1'],
        ], $this->admin->id);

        $this->service()->approve($batch, $this->admin->id);

        Bus::assertDispatched(SendOutboundMessage::class);
        $msg = OutboundMessage::query()->where('batch_id', $batch->id)->first();
        $this->assertSame('queued', $msg->status);
    }

    public function test_render_pdf_writes_file(): void
    {
        Storage::fake('local');
        $s = $this->studentWithGuardian();
        $batch = $this->service()->buildDrafts('guidance.meeting', [
            'student_ids' => [$s->id], 'audiences' => ['student', 'parent'],
            'vars' => ['rehber_adi' => 'Zeynep Rehber', 'tarih' => '27.09.2026', 'saat' => '11:00', 'yer' => 'Rehberlik'],
        ], $this->admin->id);

        $this->service()->renderPdf($batch);

        $this->assertNotNull($batch->fresh()->pdf_path);
        Storage::disk('local')->assertExists($batch->fresh()->pdf_path);
    }

    public function test_template_autocreate_and_render(): void
    {
        $tpl = $this->service()->template($this->branch->id, 'schedule.published', 'parent');
        $this->assertSame('schedule.published', $tpl->event_type);
        $this->assertSame('parent', $tpl->audience);
        $rendered = $tpl->render(['ogrenci_adi' => 'Ali Yılmaz', 'hafta' => '39.']);
        $this->assertStringContainsString('Ali Yılmaz', $rendered);
    }
}
