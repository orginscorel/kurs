<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\FinanceAccount;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\EnrollmentService;
use App\Services\Finance\PaymentService;
use App\Support\BranchContext;
use App\Support\Permissions;
use App\Support\Sequence;
use App\Sync\ChangeRecorder;
use App\Sync\Local\LocalApplier;
use App\Sync\Local\LocalCommandRecorder;
use App\Sync\Local\LocalEnrollmentService;
use App\Sync\Local\LocalPaymentService;
use App\Sync\Local\SqliteCompat;
use App\Sync\Local\SqliteCompatConnection;
use App\Sync\Models\SyncConflict;
use App\Sync\Models\SyncDevice;
use App\Sync\RecomputeService;
use App\Sync\RowCodec;
use App\Sync\Server\DeviceService;
use App\Sync\Server\PullService;
use App\Sync\Server\PushService;
use App\Sync\Server\SnapshotService;
use App\Sync\Sweeper;
use App\Sync\SyncContext;
use App\Sync\SyncNumbers;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Eşitleme altyapısı: kayıt defteri kapsamı, değişiklik günlüğü, idempotent gönderim, çakışma politikaları,
 * finansın ezilmemesi, şube kapsamı, portal hesabı reddi, numara aralıkları, SQLite uyumluluğu.
 * Bellek içi SQLite üzerinde GERÇEK migration'ların tamamı çalıştırılır (canlı veritabanına dokunmaz).
 */
class SyncInfrastructureTest extends TestCase
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
        Event::fake([\App\Events\PaymentReceived::class, \App\Events\EnrollmentCreated::class, \App\Events\StudentCreated::class,
            \App\Events\StudentStatusChanged::class, \App\Events\StudentUpdated::class]);

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

    private function push(array $changes, ?int $base = null, ?SyncDevice $device = null): array
    {
        return app(PushService::class)->push($device ?? $this->device, $changes, $base ?? $this->cursor(), now()->toIso8601String());
    }

    // ================================================================== kayıt defteri

    public function test_every_table_is_registered_and_synced_tables_are_prepared(): void
    {
        $tables = SyncSchema::listTables();
        $this->assertSame([], SyncRegistry::unknown($tables), 'Kayıt defterinde tanımsız tablo var: app/Sync/SyncRegistry.php');

        $schema = app(SyncSchema::class);
        foreach (SyncRegistry::synced() as $name => $def) {
            if (! in_array($name, $tables, true)) {
                continue;
            }
            if ($def->hasUuid()) {
                $this->assertTrue($schema->hasUuid($name), "$name tablosunda uuid yok");
            } else {
                $this->assertNotEmpty($def->key, "$name pivot tablosunun anahtarı tanımlı değil");
            }
            $this->assertSame([], $schema->unresolvedRefs($name), "$name: referans tablosu çözülemeyen *_id sütunu (SyncRegistry 'fk')");
        }
        // Finans kök belgeleri satır olarak itilemez; sistem tabloları eşitlenmez
        foreach (['payments', 'refunds', 'finance_entries', 'invoices', 'enrollments', 'promissory_notes'] as $t) {
            $this->assertFalse(SyncRegistry::get($t)->isPushable(), "$t itilebilir olmamalı");
        }
        foreach (['sessions', 'cache', 'jobs', 'login_events', 'personal_access_tokens', 'integrations', 'sync_changes'] as $t) {
            $this->assertFalse(SyncRegistry::get($t)->isSynced(), "$t eşitlenmemeli");
        }
        $this->assertSame('up', SyncRegistry::get('audit_logs')->direction);
        $this->assertContains('balance', SyncRegistry::get('finance_accounts')->exclude);
    }

    public function test_unknown_table_is_reported(): void
    {
        $this->assertSame(['yeni_modul_tablosu'], SyncRegistry::unknown(['students', 'yeni_modul_tablosu', 'sync_x']));
    }

    // ================================================================== değişiklik günlüğü

    public function test_eloquent_and_raw_writes_are_logged(): void
    {
        $s = $this->student();
        $this->assertNotEmpty($s->uuid);
        $ins = DB::table('sync_changes')->where('row_uuid', $s->uuid)->where('op', 'insert')->first();
        $this->assertNotNull($ins);
        $fields = json_decode($ins->fields, true);
        $this->assertSame('Ali', $fields['first_name']);
        $this->assertSame(DB::table('branches')->value('uuid'), $fields['branch_id'], 'referans uuid olarak yazılır');
        $this->assertArrayNotHasKey('user_id', $fields, 'portal hesabı referansı eşitlenmez');

        $s->phone = '05329998877';
        $s->save();
        $upd = DB::table('sync_changes')->where('row_uuid', $s->uuid)->where('op', 'update')->first();
        $this->assertSame(['phone', 'updated_at'], array_keys(json_decode($upd->fields, true)));

        // Olaysız yazma: süpürücü yakalar (Eloquent yazması özeti zaten günceller → çift kayıt olmaz)
        $this->assertSame(0, app(Sweeper::class)->sweep(['students'], true)['updated']);
        DB::table('students')->where('id', $s->id)->update(['school_name' => 'Erbaa Lisesi']);
        $stats = app(Sweeper::class)->sweep(['students'], true);
        $this->assertSame(1, $stats['updated']);
        $sweep = DB::table('sync_changes')->where('row_uuid', $s->uuid)->where('source', 'sweep')->first();
        $this->assertSame('Erbaa Lisesi', json_decode($sweep->fields, true)['school_name']);
        // İkinci süpürme yeni kayıt üretmez
        $this->assertSame(0, app(Sweeper::class)->sweep(['students'], true)['updated']);

        // DB::table ile eklenen satıra uuid verilir ve insert yazılır
        DB::table('subjects')->insert(['branch_id' => $this->branch->id, 'code' => 'MAT', 'name' => 'Matematik', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, app(Sweeper::class)->sweep(['subjects'])['inserted']);
        $this->assertNotNull(DB::table('subjects')->where('code', 'MAT')->value('uuid'));

        // Silme izi
        $s->delete();
        $this->assertNotNull(DB::table('sync_changes')->where('row_uuid', $s->uuid)->where('op', 'update')->where('fields', 'like', '%deleted_at%')->first());
    }

    public function test_excluded_and_filtered_rows_are_not_logged(): void
    {
        $before = $this->cursor();
        $portal = $this->makeUser('20260001', 'student', 'ogrenci');
        $this->assertSame(0, DB::table('sync_changes')->where('id', '>', $before)->where('table_name', 'users')
            ->where('row_uuid', $portal->uuid)->count(), 'öğrenci portal hesabı cihaza inmez');

        DB::table('finance_accounts')->where('id', $this->cash)->update(['balance' => '999.00']);
        $this->assertSame(0, app(Sweeper::class)->sweep(['finance_accounts'], true)['updated'], 'bakiye eşitlenmez');
    }

    // ================================================================== gönderim: idempotent + çakışma

    public function test_push_is_idempotent(): void
    {
        $uuid = (string) Str::uuid7();
        $change = ['id' => (string) Str::uuid7(), 'table' => 'students', 'op' => 'insert', 'row' => $uuid, 'at' => now()->toIso8601String(),
            'fields' => ['student_no' => '777001', 'first_name' => 'Cihaz', 'last_name' => 'Öğrenci', 'full_name' => 'Cihaz Öğrenci', 'status' => 'active']];

        $r1 = $this->push([$change]);
        $this->assertSame('accepted', $r1['results'][0]['status']);
        $r2 = $this->push([$change]);
        $this->assertSame('duplicate', $r2['results'][0]['status']);
        $this->assertSame(1, Student::query()->where('uuid', $uuid)->count());
        $this->assertSame((int) $this->branch->id, (int) Student::query()->where('uuid', $uuid)->value('branch_id'));
        $this->assertSame(1, DB::table('sync_receipts')->count());
    }

    public function test_field_level_last_writer_wins_with_conflict_record(): void
    {
        $s = $this->student();
        $base = $this->cursor();

        // Sunucuda (web) telefon değişti
        CarbonImmutable::setTestNow(now()->addMinutes(5));
        $s->refresh()->forceFill(['phone' => '05550000001'])->save();

        // Cihaz daha ÖNCE (çevrimdışıyken) telefonu ve okulu değiştirmişti → telefon: sunucu kazanır, okul uygulanır
        $old = now()->subMinutes(3)->toIso8601String();
        $r = $this->push([['id' => (string) Str::uuid7(), 'table' => 'students', 'op' => 'update', 'row' => $s->uuid, 'at' => $old,
            'fields' => ['phone' => '05440000002', 'school_name' => 'Anadolu Lisesi']]], $base);
        $this->assertSame('conflict', $r['results'][0]['status']);
        $s->refresh();
        $this->assertSame('05550000001', $s->phone);
        $this->assertSame('Anadolu Lisesi', $s->school_name);
        $c = SyncConflict::query()->where('row_uuid', $s->uuid)->where('field', 'phone')->firstOrFail();
        $this->assertSame('server', $c->winner);
        $this->assertSame('05440000002', $c->device_value);
        $this->assertStringContainsString('Ali', (string) $c->row_label);

        // Daha YENİ cihaz değişikliği → cihaz kazanır ve kaynak cihaza da geri gönderilir (echo)
        $r = $this->push([['id' => (string) Str::uuid7(), 'table' => 'students', 'op' => 'update', 'row' => $s->uuid,
            'at' => now()->addMinute()->toIso8601String(), 'fields' => ['phone' => '05440000003']]], $base);
        $this->assertSame('05440000003', $s->refresh()->phone);
        $this->assertSame('device', SyncConflict::query()->where('row_uuid', $s->uuid)->where('field', 'phone')->latest('id')->value('winner'));
        $pull = app(PullService::class)->pull($this->device, $this->admin, $base, 500);
        $phones = collect($pull['changes'])->where('row', $s->uuid)->pluck('fields.phone')->filter()->values()->all();
        $this->assertSame('05440000003', end($phones), 'çekmede son değer cihazınki olmalı');

        // Çözüm: diğer değeri uygula
        $conflict = SyncConflict::query()->where('row_uuid', $s->uuid)->where('winner', 'device')->firstOrFail();
        app(\App\Sync\Server\ConflictService::class)->resolve($conflict, 'apply_other');
        $this->assertSame('05550000001', $s->refresh()->phone);
        CarbonImmutable::setTestNow();
    }

    public function test_attendance_same_key_from_two_sides_merges_and_keeps_history(): void
    {
        $s = $this->student();
        $b = $this->branch->id;
        $ts = ['created_at' => now(), 'updated_at' => now()];
        $subject = DB::table('subjects')->insertGetId(['branch_id' => $b, 'code' => 'FIZ', 'name' => 'Fizik'] + $ts);
        $teacher = DB::table('teachers')->insertGetId(['branch_id' => $b, 'first_name' => 'Öğ', 'last_name' => 'Retmen'] + $ts);
        $room = DB::table('classrooms')->insertGetId(['branch_id' => $b, 'name' => 'D1', 'capacity' => 20] + $ts);
        $group = DB::table('class_groups')->insertGetId(['branch_id' => $b, 'academic_term_id' => DB::table('academic_terms')->value('id'),
            'program_id' => DB::table('programs')->value('id'), 'name' => '10-A'] + $ts);
        $sessionId = DB::table('lesson_sessions')->insertGetId(['class_group_id' => $group, 'subject_id' => $subject, 'teacher_id' => $teacher,
            'classroom_id' => $room, 'branch_id' => $this->branch->id, 'date' => today()->toDateString(),
            'starts_at' => now()->setTime(10, 0), 'ends_at' => now()->setTime(11, 0), 'status' => 'planned', 'uuid' => (string) Str::uuid7(),
            'created_at' => now(), 'updated_at' => now()]);
        $base = $this->cursor();
        // Sunucuda (web) yoklama: geç
        $att = \App\Models\Attendance::query()->create(['lesson_session_id' => $sessionId, 'student_id' => $s->id, 'date' => today()->toDateString(), 'status' => 'late', 'late_minutes' => 10, 'method' => 'manual']);

        // Cihaz aynı öğrenci-ders için kendi satırını (farklı uuid) daha sonra "geldi" olarak işaretledi
        $deviceRow = (string) Str::uuid7();
        $r = $this->push([['id' => (string) Str::uuid7(), 'table' => 'attendances', 'op' => 'insert', 'row' => $deviceRow,
            'at' => now()->addMinute()->toIso8601String(), 'fields' => [
                'lesson_session_id' => DB::table('lesson_sessions')->where('id', $sessionId)->value('uuid'),
                'student_id' => $s->uuid, 'date' => today()->toDateString(), 'status' => 'present', 'late_minutes' => 0, 'method' => 'manual',
            ]]], $base);
        $this->assertSame($att->uuid, $r['results'][0]['row'], 'doğal anahtarla tek satırda birleşir');
        $this->assertSame(1, DB::table('attendances')->where('student_id', $s->id)->count());
        $this->assertSame('present', DB::table('attendances')->where('id', $att->id)->value('status'));
        $this->assertTrue(SyncConflict::query()->where('kind', 'attendance')->where('field', 'status')->exists());
        // Önceki değer tarihçede
        $this->assertTrue(DB::table('sync_changes')->where('row_uuid', $att->uuid)->where('fields', 'like', '%"late"%')->exists());
        // Cihaza birleşme bildirimi
        $this->assertTrue(DB::table('sync_changes')->where('op', 'merge')->where('row_uuid', $att->uuid)->where('fields', 'like', '%'.$deviceRow.'%')->exists());
    }

    public function test_update_of_deleted_row_is_a_conflict_and_delete_after_server_update_keeps_row(): void
    {
        $s = $this->student();
        $base = $this->cursor();
        $g = \App\Models\Guardian::query()->create(['first_name' => 'Veli', 'last_name' => 'Bey', 'phone' => '05320000000']);
        $g->forceFill(['phone' => '05321111111'])->save();

        $r = $this->push([['id' => (string) Str::uuid7(), 'table' => 'guardians', 'op' => 'delete', 'row' => $g->uuid, 'at' => now()->toIso8601String()]], $base);
        $this->assertSame('conflict', $r['results'][0]['status']);
        $this->assertNull($g->refresh()->deleted_at);

        $t = \App\Models\Tag::query()->create(['name' => 'Etiket']);
        $tagUuid = $t->uuid;
        $t->delete();
        $r = $this->push([['id' => (string) Str::uuid7(), 'table' => 'tags', 'op' => 'update', 'row' => $tagUuid, 'fields' => ['name' => 'X'], 'at' => now()->toIso8601String()]]);
        $this->assertSame('conflict', $r['results'][0]['status']);
        $this->assertTrue(SyncConflict::query()->where('kind', 'delete')->where('row_uuid', $tagUuid)->exists());
    }

    // ================================================================== finans

    public function test_finance_rows_cannot_be_pushed_and_double_payment_goes_to_reconciliation(): void
    {
        $s = $this->student();
        $enrollment = app(EnrollmentService::class)->enroll($s, [
            'academic_term_id' => DB::table('academic_terms')->value('id'), 'program_id' => DB::table('programs')->value('id'),
            'list_price' => '3000', 'enrolled_on' => today()->toDateString(), 'installment_count' => 3, 'first_due_date' => today()->addMonth()->toDateString(),
        ]);
        $inst = $enrollment->installments()->orderBy('sequence')->first();

        // Ham finans satırı reddedilir
        $r = $this->push([['id' => (string) Str::uuid7(), 'table' => 'payments', 'op' => 'insert', 'row' => (string) Str::uuid7(),
            'fields' => ['amount' => '1000'], 'at' => now()->toIso8601String()]]);
        $this->assertSame('rejected', $r['results'][0]['status']);
        $this->assertSame('not_pushable', $r['results'][0]['code']);
        $this->assertSame(0, DB::table('payments')->count());

        $base = $this->cursor();
        // Web'den ilk taksit ödendi
        CarbonImmutable::setTestNow(now()->addMinute());
        app(PaymentService::class)->collect($s, ['finance_account_id' => $this->cash, 'method' => 'cash', 'amount' => '1000', 'installment_ids' => [$inst->id]]);

        // Çevrimdışı cihaz da aynı taksiti tahsil etmişti → iki tahsilat da kalır, mutabakat kuyruğu
        $payUuid = (string) Str::uuid7();
        $r = $this->push([[
            'id' => (string) Str::uuid7(), 'command' => 'payment.collect', 'at' => now()->toIso8601String(),
            'args' => ['student' => $s->uuid, 'data' => ['finance_account_id' => DB::table('finance_accounts')->where('id', $this->cash)->value('uuid'),
                'method' => 'cash', 'amount' => '1000', 'installment_ids' => [$inst->uuid], 'paid_at' => now()->subHour()->toDateTimeString()]],
            'uuids' => ['payments' => [$payUuid]],
            'numbers' => ['receipt' => ['MKB-D9-2026-000001']],
        ]], $base);
        $this->assertSame('conflict', $r['results'][0]['status'], json_encode($r));
        $this->assertSame($payUuid, $r['results'][0]['row']);
        $this->assertSame(2, DB::table('payments')->whereNull('voided_at')->count());
        $this->assertSame('MKB-D9-2026-000001', DB::table('payments')->where('uuid', $payUuid)->value('receipt_no'), 'cihazın makbuz numarası korunur');
        $this->assertTrue(SyncConflict::query()->where('kind', 'finance')->where('status', 'open')->exists());
        // Kasa bakiyesi iki tahsilatı da içerir; yeniden hesaplama sapma bulmaz
        $this->assertSame('2000.00', (string) FinanceAccount::query()->find($this->cash)->balance);
        $report = app(RecomputeService::class)->run(RecomputeService::TARGETS, false);
        $this->assertSame(0, $report['balances']['drift']);
        $this->assertSame(0, $report['installments']['drift']);

        // Aynı komut tekrar: tahsilat çoğalmaz
        CarbonImmutable::setTestNow();
    }

    public function test_local_command_capture_replays_with_same_uuids_on_server(): void
    {
        $s = $this->student('Yerel');
        $termId = DB::table('academic_terms')->value('id');
        $programId = DB::table('programs')->value('id');

        // --- yerel düğüm gibi yakala
        config(['kurs.node' => 'local', 'sync.device_code' => 'D7']);
        $this->app->bind(EnrollmentService::class, LocalEnrollmentService::class);
        $this->app->bind(PaymentService::class, LocalPaymentService::class);
        $enrollment = app(EnrollmentService::class)->enroll($s, [
            'academic_term_id' => $termId, 'program_id' => $programId, 'list_price' => '2000', 'enrolled_on' => today()->toDateString(),
            'installment_count' => 2, 'first_due_date' => today()->addMonth()->toDateString(),
        ]);
        $this->assertStringStartsWith('KYT-D7-', $enrollment->enrollment_no);
        $payment = app(PaymentService::class)->collect($s, ['finance_account_id' => $this->cash, 'method' => 'cash', 'amount' => '500']);
        $this->assertStringStartsWith('MKB-D7-', $payment->receipt_no);

        $commands = DB::table('sync_changes')->where('op', 'command')->orderBy('id')->get();
        $this->assertCount(2, $commands);
        $enrollCmd = json_decode($commands[0]->fields, true);
        $this->assertSame('enrollment.create', $enrollCmd['name']);
        $this->assertSame($s->uuid, $enrollCmd['args']['student']);
        $this->assertCount(2, $enrollCmd['uuids']['installments']);
        $this->assertSame([$enrollment->enrollment_no], $enrollCmd['numbers']['enrollment']);
        $payCmd = json_decode($commands[1]->fields, true);
        $this->assertSame([$payment->uuid], $payCmd['uuids']['payments']);

        // Yerelde finans satırı komut dışında yazılamaz
        try {
            \App\Models\Installment::query()->whereKey($enrollment->installments()->first()->id)->first()->forceFill(['amount' => 1])->save();
            $this->fail('yerel finans yazımı engellenmeliydi');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('offline_not_supported', $e->errorCode);
        }
        // Fatura numarası yerelde verilmez
        try {
            Sequence::next('invoice:EBA', 'EBA', $this->branch->id, 9);
            $this->fail('fatura numarası yerelde verilmemeliydi');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('offline_not_supported', $e->errorCode);
        }

        // --- sunucu gibi yeniden yürüt: yerel satırları sil, komutları gönder
        config(['kurs.node' => 'server']);
        $this->app->bind(EnrollmentService::class, EnrollmentService::class);
        $this->app->bind(PaymentService::class, PaymentService::class);
        $enrollUuid = $enrollment->uuid;
        $instUuids = $enrollCmd['uuids']['installments'];
        app(SyncContext::class)->applying(function () {
            DB::statement('PRAGMA foreign_keys = OFF');
            foreach (['payment_allocations', 'account_transactions', 'journal_lines', 'journal_entries', 'payments', 'installments', 'enrollments', 'activity_feed', 'audit_logs'] as $t) {
                DB::table($t)->delete();
            }
            DB::table('finance_accounts')->update(['balance' => 0]);
            DB::table('sequences')->delete();
            DB::statement('PRAGMA foreign_keys = ON');
        });
        app(RowCodec::class)->forget();

        $wire = fn ($row, $f) => ['id' => $row->change_uuid, 'command' => $f['name'], 'args' => $f['args'], 'uuids' => $f['uuids'], 'numbers' => $f['numbers'], 'at' => now()->toIso8601String()];
        $r = $this->push([$wire($commands[0], $enrollCmd), $wire($commands[1], $payCmd)]);
        $this->assertSame(['accepted', 'accepted'], array_column($r['results'], 'status'), json_encode($r));
        $this->assertSame($enrollUuid, DB::table('enrollments')->value('uuid'));
        $this->assertSame($instUuids, DB::table('installments')->orderBy('sequence')->pluck('uuid')->all());
        $this->assertSame($enrollment->enrollment_no, DB::table('enrollments')->value('enrollment_no'));
        $this->assertSame($payment->receipt_no, DB::table('payments')->value('receipt_no'));
        $this->assertEquals(500, (float) DB::table('payment_allocations')->sum('amount'));
        $this->assertSame('500.00', (string) FinanceAccount::query()->find($this->cash)->balance);
        // Komutla üretilen satırlar cihaza da iner (sunucu-otoriteli)
        $pulled = app(PullService::class)->pull($this->device, $this->admin, 0, 1000);
        $this->assertContains($enrollUuid, array_column($pulled['changes'], 'row'));
    }

    // ================================================================== şube + portal + jeton

    public function test_branch_scope_on_pull_and_push(): void
    {
        $other = Branch::query()->create(['code' => 'NIKSAR', 'name' => 'Niksar']);
        $otherUser = $this->makeUser('niksar1', 'staff', 'yonetici', $other->id);
        $otherDevice = $this->makeDevice($otherUser, $other->id);

        $s = $this->student('Merkezli');
        $pull = app(PullService::class)->pull($otherDevice, $otherUser, 0, 1000);
        $this->assertNotContains($s->uuid, array_column($pull['changes'], 'row'));
        $mine = app(PullService::class)->pull($this->device, $this->admin, 0, 1000);
        $this->assertContains($s->uuid, array_column($mine['changes'], 'row'));

        $r = app(PushService::class)->push($otherDevice, [['id' => (string) Str::uuid7(), 'table' => 'students', 'op' => 'update', 'row' => $s->uuid,
            'fields' => ['phone' => '05000000000'], 'at' => now()->toIso8601String()]], $this->cursor(), null);
        $this->assertSame('rejected', $r['results'][0]['status']);
        $this->assertSame('branch_mismatch', $r['results'][0]['code']);

        // Anlık görüntü de şube kapsamlı (alt tablolar üst kayıt üzerinden)
        $snap = app(SnapshotService::class)->page($otherDevice, $otherUser, 'students', 0, 100);
        $this->assertSame([], $snap['rows']);
        $manifest = app(SnapshotService::class)->manifest($this->device, $this->admin);
        $this->assertContains('students', array_column($manifest['tables'], 'table'));
        $order = array_column($manifest['tables'], 'table');
        $this->assertLessThan(array_search('attendances', $order), array_search('students', $order));
    }

    public function test_permission_scope_hides_tables_and_sensitive_fields(): void
    {
        $limited = $this->makeUser('rehber1', 'staff');
        Role::findOrCreate('rehberx', 'web')->syncPermissions(['students.view', 'sync.use']);
        $limited->assignRole('rehberx');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $device = $this->makeDevice($limited);

        $s = $this->student();
        $s->forceFill(\App\Support\Sensitive::nationalIdColumns('10000000146'))->save();
        app(PaymentService::class)->collect($s, ['finance_account_id' => $this->cash, 'method' => 'cash', 'amount' => '100', 'overpayment' => 'credit']);

        $pull = app(PullService::class)->pull($device, $limited->fresh(), 0, 1000);
        $tables = array_unique(array_column($pull['changes'], 'table'));
        $this->assertNotContains('payments', $tables);
        foreach ($pull['changes'] as $c) {
            if ($c['table'] === 'students') {
                $this->assertArrayNotHasKey('national_id_encrypted', (array) $c['fields']);
            }
        }
    }

    public function test_portal_accounts_cannot_pair_and_sync_tokens_are_restricted(): void
    {
        $code = app(DeviceService::class)->pairingCode($this->branch->id);
        $studentUser = $this->makeUser('20269999', 'student', 'ogrenci');

        $res = $this->postJson('/api/v1/sync/pair', ['code' => $code, 'login' => '20269999', 'password' => 'Parola123!',
            'device_name' => 'Ev PC', 'platform' => 'windows']);
        $res->assertStatus(403)->assertJsonPath('error_code', 'portal_account');

        $res = $this->postJson('/api/v1/sync/pair', ['code' => 'YANLIS-KOD', 'login' => 'mudur1', 'password' => 'Parola123!',
            'device_name' => 'Ofis', 'platform' => 'windows']);
        $res->assertStatus(422);

        $keypair = sodium_crypto_box_keypair();
        config(['kurs.data_key' => 'base64:'.base64_encode(random_bytes(32))]);
        $res = $this->postJson('/api/v1/sync/pair', ['code' => strtolower($code), 'login' => 'mudur1', 'password' => 'Parola123!',
            'device_name' => 'Ofis PC', 'platform' => 'windows', 'public_key' => base64_encode(sodium_crypto_box_publickey($keypair))]);
        $res->assertStatus(201);
        $token = $res->json('token');
        $this->assertMatchesRegularExpression('/^D\d+$/', $res->json('device.code'));
        $opened = sodium_crypto_box_seal_open(base64_decode($res->json('key_bundle.sealed')), $keypair);
        $this->assertSame(config('kurs.data_key'), json_decode($opened, true)['key'], 'kurum veri anahtarı yalnız cihazın gizli anahtarıyla açılır');

        // Cihaz jetonu eşitleme uçlarında çalışır, yönetim uçlarında çalışmaz
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/sync/status')->assertOk()->assertJsonPath('node', 'server');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/students')->assertStatus(403);

        // Normal (tam yetkili) jeton eşitleme uçlarına giremez
        $plain = $this->admin->createToken('mobil', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/sync/pull?cursor=0')->assertStatus(403)->assertJsonPath('error_code', 'device_token_required');

        // İptal edilen cihaz
        $device = SyncDevice::query()->where('uuid', $res->json('device.uuid'))->firstOrFail();
        app(DeviceService::class)->revoke($device, $this->admin);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/sync/status')->assertStatus(401);
    }

    // ================================================================== numaralar

    public function test_number_blocks_and_device_prefixes_never_collide(): void
    {
        DB::table('sequences')->insert(['branch_id' => $this->branch->id, 'name' => 'student_no', 'year' => 0, 'last_value' => 2026010]);
        $block = SyncNumbers::lease($this->branch->id, $this->device->id, 'student_no', 5);
        $this->assertSame(['name' => 'student_no', 'start' => 2026011, 'end' => 2026015], $block);
        // Sunucu sayacı bloğun ardından devam eder
        $this->assertSame(2026016, Sequence::nextNumber('student_no', 1, $this->branch->id));

        // Yerel düğüm: bloktan verir, bitince açık hata
        config(['kurs.node' => 'local', 'sync.device_code' => 'D3']);
        DB::table('sync_number_blocks')->where('device_id', $this->device->id)->update(['device_id' => null]);
        $got = [];
        for ($i = 0; $i < 5; $i++) {
            $got[] = Sequence::nextNumber('student_no', 1, $this->branch->id);
        }
        $this->assertSame([2026011, 2026012, 2026013, 2026014, 2026015], $got);
        try {
            Sequence::nextNumber('student_no', 1, $this->branch->id);
            $this->fail('blok bitince hata beklenirdi');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('number_block_exhausted', $e->errorCode);
        }
        $local = Sequence::next('receipt', 'MKB', $this->branch->id);
        config(['kurs.node' => 'server']);
        $server = Sequence::next('receipt', 'MKB', $this->branch->id);
        $this->assertSame('MKB-D3-'.now()->year.'-000001', $local);
        $this->assertSame('MKB-'.now()->year.'-000001', $server);
    }

    // ================================================================== yerel uygulayıcı + yeniden hesaplama

    public function test_local_applier_keeps_pending_local_fields_and_merges_aliases(): void
    {
        config(['kurs.node' => 'local']);
        $s = $this->student('Yerelde');
        DB::table('sync_state')->updateOrInsert(['key' => 'pushed_up_to'], ['value' => '0']);
        // Yerelde telefon değişti (henüz gönderilmedi)
        $s->forceFill(['phone' => '05001112233'])->save();

        $applier = app(LocalApplier::class);
        $res = $applier->apply(['table' => 'students', 'row' => $s->uuid, 'op' => 'update',
            'fields' => ['phone' => '05990000000', 'school_name' => 'Sunucu Okulu']]);
        $this->assertSame('applied', $res);
        $s->refresh();
        $this->assertSame('05001112233', $s->phone, 'gönderilmemiş yerel alan ezilmez');
        $this->assertSame('Sunucu Okulu', $s->school_name);
        // Uygulanan değişiklik geri itilmez
        $this->assertSame(0, app(Sweeper::class)->sweep(['students'], true)['updated']);

        // Bilinmeyen satıra kısmi güncelleme: tam satır istenir
        $this->assertSame('deferred', $applier->apply(['table' => 'students', 'row' => (string) Str::uuid7(), 'op' => 'update', 'fields' => ['phone' => '1']]));
        $this->assertCount(1, $applier->needRows['students']);

        // Birleşme: yerel uuid sunucudakine taşınır
        $canonical = (string) Str::uuid7();
        $applier->apply(['table' => 'students', 'row' => $canonical, 'op' => 'merge', 'fields' => ['alias' => $s->uuid]]);
        $this->assertSame($canonical, $s->refresh()->uuid);
        config(['kurs.node' => 'server']);
    }

    public function test_recompute_restores_derived_fields(): void
    {
        $s = $this->student();
        app(PaymentService::class)->collect($s, ['finance_account_id' => $this->cash, 'method' => 'cash', 'amount' => '250', 'overpayment' => 'credit']);
        DB::table('finance_accounts')->where('id', $this->cash)->update(['balance' => '0']);
        $report = app(RecomputeService::class)->run(['balances']);
        $this->assertSame(1, $report['balances']['drift']);
        $this->assertSame('250.00', (string) FinanceAccount::query()->find($this->cash)->balance);
    }

    // ================================================================== SQLite uyumluluğu

    public function test_sqlite_compat_rewrites_mysql_sql(): void
    {
        $this->assertSame("SELECT TIMESTAMPDIFF('MINUTE', a, b)", SqliteCompatConnection::rewrite('SELECT TIMESTAMPDIFF(MINUTE, a, b)'));
        $this->assertSame('SELECT GROUP_CONCAT(DISTINCT cg.name)', SqliteCompatConnection::rewrite("SELECT GROUP_CONCAT(DISTINCT cg.name ORDER BY cg.name SEPARATOR ', ')"));
        $this->assertSame('WHERE mysql_match(?, full_name, school_name)', SqliteCompatConnection::rewrite('WHERE MATCH(full_name, school_name) AGAINST (? IN BOOLEAN MODE)'));
        $this->assertSame('RIGHT JOIN x', SqliteCompatConnection::rewrite('RIGHT JOIN x'));

        $pdo = new \PDO('sqlite::memory:');
        SqliteCompat::register($pdo);
        $q = fn (string $sql) => $pdo->query(SqliteCompatConnection::rewrite($sql))->fetchColumn();
        $this->assertSame('ab', $q("SELECT CONCAT('a', 'b')"));
        $this->assertSame('2026-09', $q("SELECT DATE_FORMAT('2026-09-17 10:00:00', '%Y-%m')"));
        $this->assertEquals(90, $q("SELECT TIMESTAMPDIFF(MINUTE, '2026-09-17 10:00:00', '2026-09-17 11:30:00')"));
        $this->assertEquals(2, $q("SELECT FIELD('pos', 'cash', 'pos', 'bank')"));
        $this->assertEquals(1, $q("SELECT '2026001' REGEXP '^[0-9]+$'"));
        $this->assertEquals(5400, $q("SELECT TIME_TO_SEC(TIMEDIFF('2026-09-17 11:30:00', '2026-09-17 10:00:00'))"));
        $this->assertSame('233', $q("SELECT RIGHT(REGEXP_REPLACE('0532 1-233', '[^0-9]', ''), 3)"));
        $this->assertEquals(1, $q("SELECT MATCH(a, b) AGAINST ('+ahm* +yıl*' IN BOOLEAN MODE) FROM (SELECT 'Ahmet Yılmaz' AS a, 'Lise' AS b)") > 0 ? 1 : 0);
        $this->assertEquals(3, $q('SELECT WEEKDAY(\'2026-09-17\')'));
    }

    public function test_pull_cursor_waits_for_young_gaps(): void
    {
        $s = $this->student();
        $max = $this->cursor();
        // Sürmekte olan (henüz görünmeyen) işlem: arada boşluk olan yeni kayıt
        DB::table('sync_changes')->insert(['id' => $max + 2, 'change_uuid' => (string) Str::uuid7(), 'branch_id' => $this->branch->id,
            'table_name' => 'students', 'row_uuid' => $s->uuid, 'op' => 'update', 'fields' => '{"phone":"1"}', 'source' => 'web',
            'created_at' => now()->format('Y-m-d H:i:s.v')]);
        $pull = app(PullService::class)->pull($this->device, $this->admin, $max, 100);
        $this->assertSame($max, $pull['cursor'], 'yeni boşlukta imleç ilerlemez');
        $this->assertTrue($pull['more']);

        DB::table('sync_changes')->where('id', $max + 2)->update(['created_at' => now()->subMinutes(5)->format('Y-m-d H:i:s.v')]);
        $pull = app(PullService::class)->pull($this->device, $this->admin, $max, 100);
        $this->assertSame($max + 2, $pull['cursor'], 'eski boşluk geri alınmış işlem sayılır');
    }
}
