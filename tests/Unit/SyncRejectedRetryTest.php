<?php

namespace Tests\Unit;

use App\Events\EnrollmentCreated;
use App\Events\PaymentReceived;
use App\Events\StudentCreated;
use App\Events\StudentStatusChanged;
use App\Events\StudentUpdated;
use App\Models\Branch;
use App\Models\FinanceAccount;
use App\Models\Student;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\Permissions;
use App\Sync\ChangeRecorder;
use App\Sync\Local\LocalSyncEngine;
use App\Sync\Local\RejectedRetry;
use App\Sync\Models\SyncConflict;
use App\Sync\Models\SyncDevice;
use App\Sync\Server\PushService;
use App\Sync\Sweeper;
use App\Sync\SyncSchema;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Reddedilen değişiklikleri yeniden deneme: sunucuda reddedilmiş makbuzun yeniden değerlendirilmesi,
 * yerelde otomatik/elle deneme, sonsuz döngü olmaması, 'missing_reference' zincirinin açılması.
 */
class SyncRejectedRetryTest extends TestCase
{
    private Branch $branch;

    private User $admin;

    private SyncDevice $device;

    private int $cash;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }
        config(['kurs.silent_events' => true, 'kurs.node' => 'server', 'sync.sweep_before_pull_seconds' => 0]);
        Event::fake([PaymentReceived::class, EnrollmentCreated::class, StudentCreated::class,
            StudentStatusChanged::class, StudentUpdated::class]);

        $this->artisan('migrate', ['--force' => true])->run();
        app(SyncSchema::class)->flush();
        app(ChangeRecorder::class)->reset();

        $this->branch = Branch::query()->create(['code' => 'ERBAA', 'name' => 'Merkez']);
        app(BranchContext::class)->set($this->branch->id);
        foreach (Permissions::all() as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $role = Role::findOrCreate('yonetici', 'web');
        $role->syncPermissions(Permissions::all());
        Role::findOrCreate('ogrenci', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = $this->makeUser('mudur1', 'staff', 'yonetici');
        $this->cash = FinanceAccount::query()->create(['kind' => 'cash', 'name' => 'Kasa', 'is_active' => true, 'currency' => 'TRY'])->id;
        DB::table('academic_terms')->insert(['branch_id' => $this->branch->id, 'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('programs')->insert(['branch_id' => $this->branch->id, 'code' => 'LGS', 'name' => 'LGS', 'kind' => 'group', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        Auth::setUser($this->admin);
        app(SyncSchema::class)->backfillUuids();
        app(SyncSchema::class)->flush();
        app(Sweeper::class)->baseline();
        $this->device = $this->makeDevice($this->admin);
    }

    private function makeUser(string $username, string $type, ?string $role = null, ?int $branchId = null): User
    {
        $u = User::query()->create([
            'branch_id' => $branchId ?? $this->branch->id, 'name' => ucfirst($username), 'username' => $username,
            'user_type' => $type, 'password' => 'Parola123!', 'is_active' => true,
        ]);
        if ($role) {
            $u->assignRole($role);
        }

        return $u;
    }

    private function makeDevice(User $user, ?int $branchId = null): SyncDevice
    {
        $token = $user->createToken('sync:test', ['sync']);

        return SyncDevice::query()->create([
            'uuid' => (string) Str::uuid7(), 'branch_id' => $branchId ?? $this->branch->id, 'user_id' => $user->id,
            'code' => 'D'.random_int(1, 9999), 'name' => 'Test PC', 'platform' => 'windows', 'token_id' => $token->accessToken->id,
            'status' => 'active', 'paired_at' => now(),
        ]);
    }

    private function student(string $first = 'Ali', string $phone = '05321112233'): Student
    {
        $s = new Student;
        $s->forceFill(['student_no' => (string) random_int(100000, 999999), 'first_name' => $first, 'last_name' => 'Yılmaz',
            'phone' => $phone, 'status' => 'active'])->save();

        return $s->refresh();
    }

    private function cursor(): int
    {
        return (int) DB::table('sync_changes')->max('id');
    }

    private function push(array $changes): array
    {
        return app(PushService::class)->push($this->device, $changes, $this->cursor(), now()->toIso8601String());
    }

    // ================================================================== sunucu

    public function test_server_reevaluates_rejected_receipt_and_keeps_accepted_idempotent(): void
    {
        $studentUuid = (string) Str::uuid7();
        $noteRow = (string) Str::uuid7();
        $change = ['id' => (string) Str::uuid7(), 'table' => 'student_notes', 'op' => 'insert', 'row' => $noteRow,
            'at' => now()->toIso8601String(), 'fields' => ['student_id' => $studentUuid, 'body' => 'Veli arandı']];

        $r = $this->push([$change]);
        $this->assertSame('rejected', $r['results'][0]['status']);
        $this->assertSame('missing_reference', $r['results'][0]['code']);
        $this->assertSame('rejected', DB::table('sync_receipts')->where('change_uuid', $change['id'])->value('status'));
        $this->assertSame(1, SyncConflict::query()->where('change_uuid', $change['id'])->where('status', 'open')->count());
        $rejectedTotal = (int) $this->device->fresh()->rejected_total;

        // Yeniden deneme hâlâ reddediliyor: yeni çakışma kaydı açılmaz, sayaç şişmez
        $r = $this->push([$change]);
        $this->assertSame('rejected', $r['results'][0]['status']);
        $this->assertSame(1, SyncConflict::query()->where('change_uuid', $change['id'])->count());
        $this->assertSame($rejectedTotal, (int) $this->device->fresh()->rejected_total);

        // Üst kayıt artık sunucuda → aynı change_uuid yeniden değerlendirilir ve KABUL edilir ('duplicate' değil)
        $s = $this->student();
        DB::table('students')->where('id', $s->id)->update(['uuid' => $studentUuid]);
        $r = $this->push([$change]);
        $this->assertSame('accepted', $r['results'][0]['status']);
        $this->assertTrue($r['results'][0]['retried'] ?? false);
        $this->assertSame('accepted', DB::table('sync_receipts')->where('change_uuid', $change['id'])->value('status'));
        $this->assertSame(1, DB::table('student_notes')->where('uuid', $noteRow)->count());
        $this->assertSame('resolved', SyncConflict::query()->where('change_uuid', $change['id'])->value('status'), 'reddedildi kaydı kendiliğinden kapanır');

        // Kabul edilmiş makbuz: eskisi gibi duplicate(previous=accepted), ikinci kez yürütülmez
        $r = $this->push([$change]);
        $this->assertSame('duplicate', $r['results'][0]['status']);
        $this->assertSame('accepted', $r['results'][0]['previous']);
        $this->assertSame(1, DB::table('student_notes')->where('uuid', $noteRow)->count());
    }

    // ================================================================== yerel

    private function localChange(string $op, string $table, array $fields = []): int
    {
        return (int) DB::table('sync_changes')->insertGetId([
            'change_uuid' => (string) Str::uuid7(), 'branch_id' => $this->branch->id, 'table_name' => $table, 'row_uuid' => (string) Str::uuid7(),
            'op' => $op, 'fields' => json_encode($fields), 'source' => 'local', 'status' => 'rejected', 'error' => 'Reddedildi', 'created_at' => now(),
        ]);
    }

    /** @param callable(array): array $responder değişiklik → sonuç */
    private function fakeServer(callable $responder, array &$sent): void
    {
        config(['sync.server_url' => 'https://sunucu.test', 'sync.device_token' => 'cihaz-jetonu']);
        Http::fake(function (HttpRequest $r) use ($responder, &$sent) {
            if (! str_contains($r->url(), 'sync/push')) {
                return Http::response(['rows' => []]);
            }
            $results = [];
            foreach ($r['changes'] as $c) {
                $sent[] = $c['id'];
                $results[] = ['id' => $c['id']] + $responder($c);
            }

            return Http::response(['results' => $results, 'cursor' => 0]);
        });
    }

    public function test_local_retry_on_startup_chain_and_duplicate_accepted_completes(): void
    {
        $plan = app(RejectedRetry::class);
        $parent = $this->localChange('insert', 'leads');
        $child = $this->localChange('insert', 'lead_activities');
        $command = $this->localChange('command', 'payments', ['name' => 'payment.collect', 'args' => [], 'uuids' => [], 'numbers' => []]);
        $plan->noteRejected($parent, 'validation_failed', false);
        $plan->noteRejected($child, 'missing_reference', false);
        $plan->noteRejected($command, 'business_rule', false);
        $uuid = fn (int $id) => DB::table('sync_changes')->where('id', $id)->value('change_uuid');

        // Tetikleyici yok ve geri çekilme süresi dolmadı → hiçbir şey gönderilmez
        $sent = [];
        $parentAcceptedAt = null;
        $this->fakeServer(function ($c) use ($uuid, $parent, &$parentAcceptedAt, &$sent) {
            if ($c['id'] === $uuid($parent)) {
                $parentAcceptedAt = count($sent);

                return ['status' => 'accepted'];
            }

            // Alt kayıt: üst kayıt ÖNCEKİ bir istekte kabul edilene kadar missing_reference (aynı pakette de reddedilir →
            // zincir geçişi gerekir); sonra sunucu önceden kabul etmiş (elle düzeltilmiş) sayılır
            return $parentAcceptedAt !== null && count($sent) > $parentAcceptedAt + 1
                ? ['status' => 'duplicate', 'previous' => 'accepted']
                : ['status' => 'rejected', 'code' => 'missing_reference', 'message' => 'Bağlı kayıt yok'];
        }, $sent);
        $engine = app(LocalSyncEngine::class);
        $this->assertSame(0, $engine->retryRejected()['sent']);
        $this->assertSame([], $sent);

        // Açılış: satır değişiklikleri denenir; komut (finans) otomatik denenmez
        $plan->markStartup();
        $out = $engine->retryRejected();
        $this->assertNotContains($uuid($command), $sent, 'komut kendiliğinden yeniden yürütülmez');
        $this->assertSame('pushed', DB::table('sync_changes')->where('id', $parent)->value('status'));
        $this->assertSame('pushed', DB::table('sync_changes')->where('id', $child)->value('status'), 'duplicate(previous=accepted) tamamlandı sayılır');
        $this->assertSame(2, $out['passes'], 'zincir: üst kayıt kabul edilince alt kayıt aynı turda yeniden gönderildi');
        $this->assertSame(2, $out['accepted']);
        $this->assertArrayNotHasKey($parent, $plan->meta());
        $this->assertArrayHasKey($command, $plan->meta());

        // Elle "Yeniden dene": komut da gönderilir
        $plan->requestManual([$command]);
        $sent = [];
        $engine->retryRejected();
        $this->assertSame([$uuid($command)], $sent);
    }

    public function test_chain_opens_in_same_cycle(): void
    {
        $plan = app(RejectedRetry::class);
        $child = $this->localChange('insert', 'lead_activities');
        $plan->noteRejected($child, 'missing_reference', false);
        $uuid = DB::table('sync_changes')->where('id', $child)->value('change_uuid');

        // Zincir: bu turda bir değişiklik kabul edildi (üst kayıt gitti) → missing_reference beklemeden denenir
        $sent = [];
        $this->fakeServer(fn ($c) => ['status' => 'accepted'], $sent);
        app(LocalSyncEngine::class)->retryRejected(1);
        $this->assertSame([$uuid], $sent);
        $this->assertSame('pushed', DB::table('sync_changes')->where('id', $child)->value('status'));
    }

    public function test_retry_has_no_infinite_loop(): void
    {
        $plan = app(RejectedRetry::class);

        // Sürekli reddedilen: her açılışta en fazla bir kez, MAX_AUTO denemeden sonra yalnız elle
        $bad = $this->localChange('update', 'students');
        $plan->noteRejected($bad, 'validation_failed', false);
        $sent = [];
        $this->fakeServer(fn ($c) => ['status' => 'rejected', 'code' => 'validation_failed', 'message' => 'Geçersiz'], $sent);
        for ($i = 0; $i < RejectedRetry::MAX_AUTO + 4; $i++) {
            $plan->markStartup();
            $out = app(LocalSyncEngine::class)->retryRejected(5);
            $this->assertLessThanOrEqual(1, $out['passes'], 'ilerleme yoksa tur içinde tekrar yok');
        }
        $this->assertCount(RejectedRetry::MAX_AUTO, $sent, 'otomatik deneme sınırı');
        $this->assertSame(RejectedRetry::MAX_AUTO, $plan->meta()[$bad]['n']);
        $this->assertGreaterThan(time() + 3600, $plan->meta()[$bad]['next'], 'geri çekilme');
        $this->assertSame('rejected', DB::table('sync_changes')->where('id', $bad)->value('status'));
        $row = collect($plan->list())->firstWhere('id', $bad);
        $this->assertFalse($row['auto']);
        $this->assertSame('Öğrenci', $row['table_label']);
    }
}
