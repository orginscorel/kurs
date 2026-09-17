<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\CollectionNote;
use App\Models\FinanceAccount;
use App\Models\FinanceEntry;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentCardDetail;
use App\Models\PromissoryNote;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\AccountService;
use App\Services\Finance\CollectionService;
use App\Services\Finance\EnrollmentService;
use App\Services\Finance\FinanceEntryService;
use App\Services\Finance\InstallmentPlanService;
use App\Services\Finance\PaymentService;
use App\Services\Finance\PromissoryNoteService;
use App\Services\Finance\ReconciliationService;
use App\Services\Finance\RefundService;
use App\Support\BranchContext;
use App\Support\Permissions;
use App\Support\Sensitive;
use App\Sync\ChangeRecorder;
use App\Sync\Commands\CommandCodec;
use App\Sync\Files\FileIndex;
use App\Sync\Files\FileSyncService;
use App\Sync\Local\LocalAccountService;
use App\Sync\Local\LocalCollectionService;
use App\Sync\Local\LocalFileSync;
use App\Sync\Local\LocalFinanceEntryService;
use App\Sync\Local\LocalInstallmentPlanService;
use App\Sync\Local\LocalPaymentService;
use App\Sync\Local\LocalPromissoryNoteService;
use App\Sync\Local\LocalReconciliationService;
use App\Sync\Local\LocalRefundService;
use App\Sync\Local\SqliteCompatConnection;
use App\Sync\Models\SyncConflict;
use App\Sync\Models\SyncDevice;
use App\Sync\RecomputeService;
use App\Sync\RowCodec;
use App\Sync\Server\PushService;
use App\Sync\Sweeper;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Eşitleme eksikleri: kurum veri anahtarı taşıması, dosya eşitlemesi, yeni finans komutları (yerel yakalama →
 * sunucuda aynı uuid/numarayla yeniden yürütme, mutabakat), Bearer isteklerinde oturumsuzluk, çevrimiçi parola.
 * Bellek içi SQLite üzerinde gerçek migration'lar (canlı veritabanına dokunmaz).
 */
class SyncGapsTest extends TestCase
{
    private Branch $branch;

    private User $admin;

    private SyncDevice $device;

    private int $cash;

    private int $bank;

    private int $pos;

    /** @var array<int, string> cihaz id => düz jeton */
    private array $tokens = [];

    /** @var array<class-string, class-string> */
    private const LOCAL_BINDINGS = [
        PaymentService::class => LocalPaymentService::class,
        RefundService::class => LocalRefundService::class,
        FinanceEntryService::class => LocalFinanceEntryService::class,
        AccountService::class => LocalAccountService::class,
        ReconciliationService::class => LocalReconciliationService::class,
        PromissoryNoteService::class => LocalPromissoryNoteService::class,
        CollectionService::class => LocalCollectionService::class,
        InstallmentPlanService::class => LocalInstallmentPlanService::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }
        config(['kurs.silent_events' => true, 'kurs.node' => 'server', 'kurs.data_key' => null, 'sync.sweep_before_pull_seconds' => 0, 'sync.files_index_seconds' => 0]);
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
        Role::findOrCreate('yonetici', 'web')->syncPermissions(Permissions::all());
        Role::findOrCreate('ogrenci', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = $this->makeUser('mudur1', 'staff', 'yonetici');
        $this->cash = FinanceAccount::query()->create(['kind' => 'cash', 'name' => 'Kasa', 'is_active' => true, 'currency' => 'TRY'])->id;
        $this->bank = FinanceAccount::query()->create(['kind' => 'bank', 'name' => 'Banka', 'is_active' => true, 'currency' => 'TRY'])->id;
        $this->pos = FinanceAccount::query()->create(['kind' => 'pos', 'name' => 'POS', 'is_active' => true, 'currency' => 'TRY'])->id;
        DB::table('academic_terms')->insert(['branch_id' => $this->branch->id, 'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('programs')->insert(['branch_id' => $this->branch->id, 'code' => 'LGS', 'name' => 'LGS', 'kind' => 'group', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        Auth::setUser($this->admin);
        app(SyncSchema::class)->backfillUuids();
        app(SyncSchema::class)->flush();
        app(Sweeper::class)->baseline();
        $this->device = $this->makeDevice($this->admin);
    }

    protected function tearDown(): void
    {
        config(['kurs.node' => 'server', 'kurs.data_key' => null]);
        parent::tearDown();
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
        $token = $user->createToken('sync:test-'.Str::random(6), ['sync']);
        $device = SyncDevice::query()->create([
            'uuid' => (string) Str::uuid7(), 'branch_id' => $branchId ?? $this->branch->id, 'user_id' => $user->id,
            'code' => 'D'.random_int(1, 9999), 'name' => 'Test PC', 'platform' => 'macos', 'token_id' => $token->accessToken->id,
            'status' => 'active', 'paired_at' => now(),
        ]);
        $this->tokens[$device->id] = $token->plainTextToken;

        return $device;
    }

    private function student(string $first = 'Ali'): Student
    {
        $s = new Student;
        $s->forceFill(['student_no' => (string) random_int(100000, 999999), 'first_name' => $first, 'last_name' => 'Yılmaz',
            'phone' => '05321112233', 'status' => 'active'])->save();

        return $s->refresh();
    }

    private function enroll(Student $s, string $price = '3000', int $count = 3): \App\Models\Enrollment
    {
        return app(EnrollmentService::class)->enroll($s, [
            'academic_term_id' => DB::table('academic_terms')->value('id'), 'program_id' => DB::table('programs')->value('id'),
            'list_price' => $price, 'enrolled_on' => today()->toDateString(), 'installment_count' => $count,
            'first_due_date' => today()->addMonth()->toDateString(),
        ]);
    }

    private function useNode(string $node): void
    {
        config(['kurs.node' => $node, 'sync.device_code' => $node === 'local' ? 'D7' : null]);
        foreach (self::LOCAL_BINDINGS as $abstract => $local) {
            $this->app->bind($abstract, $node === 'local' ? $local : $abstract);
        }
        app(RowCodec::class)->forget();
    }

    /**
     * Yerel düğüm gibi çalıştırır, oluşan komutları toplar ve TÜM yerel yazmaları geri alır
     * (sunucu durumu yerel işlemden önceki hale döner; komutlar sonra sunucuya gönderilir).
     *
     * @return array{0: list<array<string, mixed>>, 1: mixed}
     */
    private function captureLocal(callable $fn): array
    {
        $cursor = (int) DB::table('sync_changes')->max('id');
        $this->useNode('local');
        DB::beginTransaction();
        try {
            $result = $fn();
            $commands = DB::table('sync_changes')->where('id', '>', $cursor)->where('op', 'command')->orderBy('id')->get()
                ->map(function ($r) {
                    $f = json_decode($r->fields, true);

                    return ['id' => $r->change_uuid, 'command' => $f['name'], 'args' => $f['args'], 'uuids' => $f['uuids'],
                        'numbers' => $f['numbers'], 'touched' => $f['touched'], 'at' => now()->toIso8601String()];
                })->all();
        } finally {
            DB::rollBack();
            $this->useNode('server');
        }

        return [$commands, $result];
    }

    private function push(array $changes, ?SyncDevice $device = null): array
    {
        $wire = array_map(fn ($c) => array_diff_key($c, ['touched' => 1]), $changes);

        return app(PushService::class)->push($device ?? $this->device, $wire, (int) DB::table('sync_changes')->max('id'), now()->toIso8601String());
    }

    private function assertNoDrift(): void
    {
        $report = app(RecomputeService::class)->run(['balances', 'installments'], false);
        $this->assertSame(0, $report['balances']['drift'], 'bakiye sapması');
        $this->assertSame(0, $report['installments']['drift'], 'taksit sapması');
    }

    // ================================================================== kurum veri anahtarı

    public function test_data_key_migration_is_idempotent_reversible_and_reads_both_keys(): void
    {
        $s = $this->student('Tc');
        $s->forceFill(Sensitive::nationalIdColumns('10000000146'))->save();
        $g = \App\Models\Guardian::query()->create(['first_name' => 'Veli', 'last_name' => 'Bey', 'phone' => '05320000000'] + Sensitive::nationalIdColumns('10000000078'));
        $enrollment = $this->enroll($s);
        $note = PromissoryNote::query()->create(['branch_id' => $this->branch->id, 'note_no' => 'SNT-1', 'installment_id' => $enrollment->installments()->first()->id,
            'enrollment_id' => $enrollment->id, 'student_id' => $s->id, 'amount' => '1000', 'due_date' => today()->addMonth(), 'issue_date' => today(),
            'debtor_name' => 'Veli Bey', 'debtor_tax_id' => '10000000078', 'payee_name' => 'Kurum', 'issue_place' => 'Erbaa', 'payment_place' => 'Erbaa']);
        $oldCipher = DB::table('students')->where('id', $s->id)->value('national_id_encrypted');
        $oldHash = DB::table('students')->where('id', $s->id)->value('national_id_hash');
        $this->assertSame('10000000146', Crypt::decryptString($oldCipher), 'anahtar yokken APP_KEY kullanılır');

        // Anahtar yokken deneme çalışır (bellekte geçici anahtar), hiçbir şey yazmaz
        $this->artisan('kurs:data-key', ['action' => 'migrate', '--dry-run' => true])->assertSuccessful();
        $this->assertSame($oldCipher, DB::table('students')->where('id', $s->id)->value('national_id_encrypted'));
        config(['kurs.data_key' => null]);

        // Anahtar tanımlandı, henüz taşınmadı: eski değerler okunur, eski özetle arama bulur
        config(['kurs.data_key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertSame('10000000146', Sensitive::decrypt($oldCipher));
        $this->assertContains($oldHash, Sensitive::hashes('10000000146'));
        $this->assertSame($s->id, Student::query()->whereIn('national_id_hash', Sensitive::hashes('10000000146'))->value('id'));
        $this->assertSame('10000000078', PromissoryNote::query()->find($note->id)->debtor_tax_id);

        $this->artisan('kurs:data-key', ['action' => 'migrate', '--dry-run' => true])->assertSuccessful();
        $this->assertSame($oldCipher, DB::table('students')->where('id', $s->id)->value('national_id_encrypted'), 'deneme yazmaz');

        $this->artisan('kurs:data-key', ['action' => 'migrate', '--force' => true, '--no-backup' => true])->assertSuccessful();
        $newCipher = DB::table('students')->where('id', $s->id)->value('national_id_encrypted');
        $this->assertNotSame($oldCipher, $newCipher);
        $this->assertSame('data', Sensitive::decryptDetailed($newCipher)['key']);
        $this->assertSame(Sensitive::hash('10000000146'), DB::table('students')->where('id', $s->id)->value('national_id_hash'));
        $this->assertSame('data', Sensitive::decryptDetailed(DB::table('guardians')->where('id', $g->id)->value('national_id_encrypted'))['key']);
        $this->assertSame('data', Sensitive::decryptDetailed(DB::table('promissory_notes')->where('id', $note->id)->value('debtor_tax_id'))['key']);
        $this->assertSame('10000000078', PromissoryNote::query()->find($note->id)->debtor_tax_id);
        $this->assertSame(3, DB::table('sync_key_backups')->count());
        $this->assertSame(0, DB::table('sync_key_backups')->whereNotNull('old_value')->where('old_value', 'like', '%10000000146%')->count(), 'yedekte düz TC yok');

        // İdempotent: ikinci çalıştırma hiçbir şey değiştirmez
        $this->artisan('kurs:data-key', ['action' => 'migrate', '--force' => true, '--no-backup' => true])->assertSuccessful();
        $this->assertSame($newCipher, DB::table('students')->where('id', $s->id)->value('national_id_encrypted'));
        $this->assertSame(3, DB::table('sync_key_backups')->count());

        // Yeni kayıt veri anahtarıyla; tekillik denetimi yeni özetle çalışır
        $this->assertSame('data', Sensitive::decryptDetailed(Sensitive::encryptString('12345678950'))['key']);

        // Geri alma: eski şifreli değer ve özet döner (taşımadan sonra değişen satır atlanır)
        DB::table('guardians')->where('id', $g->id)->update(['national_id_encrypted' => Sensitive::encryptString('10000000078')]);
        $this->artisan('kurs:data-key', ['action' => 'rollback', '--force' => true])->assertSuccessful();
        $this->assertSame($oldCipher, DB::table('students')->where('id', $s->id)->value('national_id_encrypted'));
        $this->assertSame($oldHash, DB::table('students')->where('id', $s->id)->value('national_id_hash'));
        $this->assertSame('data', Sensitive::decryptDetailed(DB::table('guardians')->where('id', $g->id)->value('national_id_encrypted'))['key'], 'değişmiş satıra dokunulmaz');
        $this->assertSame(1, DB::table('sync_key_backups')->whereNull('restored_at')->count());
    }

    public function test_data_key_status_generate_guard_and_device_status_fingerprint(): void
    {
        $this->artisan('kurs:data-key', ['action' => 'status'])->assertSuccessful();
        config(['kurs.data_key' => config('app.key')]);
        $this->artisan('kurs:data-key', ['action' => 'migrate', '--force' => true, '--no-backup' => true])->assertFailed();
        $this->artisan('kurs:data-key', ['action' => 'generate', '--force' => true])->assertFailed();   // zaten tanımlı
        config(['kurs.data_key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->app['auth']->forgetGuards();
        $res = $this->withToken($this->tokens[$this->device->id])->getJson('/api/v1/sync/status')->assertOk();
        $this->assertSame(Sensitive::dataKeyFingerprint(), $res->json('data_key'));
        $this->assertSame(12, strlen((string) $res->json('data_key')));
        $this->assertStringNotContainsString((string) config('kurs.data_key'), $res->getContent());
    }

    // ================================================================== finans komutları

    public function test_finance_commands_are_replayed_with_same_uuids_and_document_numbers(): void
    {
        $s = $this->student('Komut');
        $enrollment = $this->enroll($s);
        $first = $enrollment->installments()->orderBy('sequence')->first();
        $payment = app(PaymentService::class)->collect($s, ['finance_account_id' => $this->cash, 'method' => 'cash', 'amount' => '1000', 'installment_ids' => [$first->id]]);
        $posPayment = app(PaymentService::class)->collect($s, ['finance_account_id' => $this->pos, 'method' => 'pos', 'amount' => '500', 'overpayment' => 'credit']);
        $detail = PaymentCardDetail::query()->where('payment_id', $posPayment->id)->firstOrFail();
        $rent = \App\Models\FinanceCategory::query()->create(['direction' => 'expense', 'code' => 'rent', 'name' => 'Kira']);

        [$commands, $local] = $this->captureLocal(function () use ($s, $payment, $enrollment, $detail, $rent) {
            $out = [];
            $refund = app(RefundService::class)->refund($payment, ['amount' => '200', 'finance_account_id' => $this->cash, 'method' => 'cash', 'reason' => 'Kısmi iade testi']);
            $out['refund'] = [$refund->uuid, $refund->refund_no];
            $entry = app(FinanceEntryService::class)->record(['direction' => 'expense', 'finance_category_id' => $rent->id, 'finance_account_id' => $this->cash,
                'amount' => '50', 'entry_date' => today()->toDateString(), 'description' => 'Kırtasiye']);
            $out['entry'] = $entry->uuid;
            app(FinanceEntryService::class)->void($entry, 'Yanlış kayıt');
            $transfer = app(AccountService::class)->transfer($this->cash, $this->bank, '100', today()->toDateString(), 'Bankaya');
            $out['transfer'] = $transfer->uuid;
            $settlement = app(ReconciliationService::class)->settle(['pos_account_id' => $this->pos, 'bank_account_id' => $this->bank,
                'deposit_date' => today()->toDateString(), 'actual_net' => '490', 'detail_ids' => [$detail->id]]);
            $out['settlement'] = $settlement->uuid;
            $note = app(CollectionService::class)->add(['student_id' => $s->id, 'kind' => 'promise', 'promised_date' => today()->addWeek()->toDateString(), 'promised_amount' => '750']);
            $out['collection'] = $note->uuid;
            app(CollectionService::class)->setStatus($note, 'kept');
            app(InstallmentPlanService::class)->adjustPrice($enrollment, ['discount_amount' => '300', 'scholarship_amount' => '0']);
            $rows = Installment::query()->where('enrollment_id', $enrollment->id)->orderBy('sequence')->get()
                ->map(fn ($i) => ['id' => $i->id, 'due_date' => $i->status === 'paid' ? $i->due_date->toDateString() : $i->due_date->addDays(10)->toDateString(), 'amount' => (string) $i->amount])->all();
            app(InstallmentPlanService::class)->restructure($enrollment->fresh(), $rows, 'Vade ertelendi');
            $installments = Installment::query()->where('enrollment_id', $enrollment->id)->whereIn('status', ['pending', 'partial', 'overdue'])->get();
            $notes = app(PromissoryNoteService::class)->prepare($installments);
            $out['notes'] = $notes->map(fn ($n) => [$n->uuid, $n->note_no])->values()->all();
            app(PromissoryNoteService::class)->pdf($notes, 3);
            $out['plan'] = Installment::query()->where('enrollment_id', $enrollment->id)->orderBy('sequence')->get(['uuid', 'amount', 'due_date'])
                ->map(fn ($i) => [$i->uuid, (string) $i->amount, $i->due_date->toDateString()])->all();
            $this->assertStringStartsWith('IAD-D7-', $refund->refund_no);
            $this->assertStringStartsWith('SNT-D7-', $notes->first()->note_no);

            return $out;
        });

        $names = array_column($commands, 'command');
        $this->assertSame(['refund.create', 'finance_entry.create', 'finance_entry.void', 'account_transfer.create', 'pos_settlement.create',
            'collection_note.add', 'collection_note.status', 'installment_plan.adjust_price', 'installment_plan.restructure',
            'promissory_note.prepare', 'promissory_note.print'], $names);
        $prepare = $commands[9];
        $this->assertArrayNotHasKey('promissory_notes', $prepare['uuids'], 'senetler taksit bazında argümanda');
        $this->assertCount(count($local['notes']), $prepare['args']['notes']);
        $this->assertNotEmpty($commands[8]['touched']['installments'] ?? [], 'plan satırları dokunulan');
        $this->assertNotEmpty($commands[4]['touched']['payment_card_details'] ?? []);
        // Yerel işlemler geri alındı: sunucuda henüz yok
        $this->assertSame(0, DB::table('refunds')->count());

        $r = $this->push($commands);
        $this->assertSame(array_fill(0, count($commands), 'accepted'), array_column($r['results'], 'status'), json_encode($r['results'], JSON_UNESCAPED_UNICODE));

        $this->assertSame($local['refund'], [DB::table('refunds')->value('uuid'), DB::table('refunds')->value('refund_no')], 'iade uuid + numarası aynı');
        $this->assertNotNull(DB::table('finance_entries')->where('uuid', $local['entry'])->value('voided_at'));
        $this->assertTrue(DB::table('account_transfers')->where('uuid', $local['transfer'])->exists());
        $this->assertTrue(DB::table('pos_settlements')->where('uuid', $local['settlement'])->exists());
        $this->assertSame(DB::table('pos_settlements')->where('uuid', $local['settlement'])->value('id'), DB::table('payment_card_details')->where('id', $detail->id)->value('pos_settlement_id'));
        $this->assertSame('kept', CollectionNote::query()->where('uuid', $local['collection'])->value('status'));
        foreach ($local['notes'] as [$uuid, $no]) {
            $this->assertSame($no, PromissoryNote::query()->where('uuid', $uuid)->value('note_no'), 'basılı senet numarası korunur');
            $this->assertSame(1, (int) PromissoryNote::query()->where('uuid', $uuid)->value('print_count'));
        }
        $this->assertSame($local['plan'], Installment::query()->where('enrollment_id', $enrollment->id)->orderBy('sequence')->get(['uuid', 'amount', 'due_date'])
            ->map(fn ($i) => [$i->uuid, (string) $i->amount, $i->due_date->toDateString()])->all(), 'ödeme planı aynı');
        $this->assertSame(0, bccomp('2700', (string) DB::table('enrollments')->where('id', $enrollment->id)->value('net_price'), 2));
        $this->assertSame(0, SyncConflict::query()->count());
        $this->assertNoDrift();

        // Aynı paket tekrar: çoğalmaz
        $again = $this->push($commands);
        $this->assertSame(array_fill(0, count($commands), 'duplicate'), array_column($again['results'], 'status'));
        $this->assertSame(1, DB::table('refunds')->count());
        // Aynı komut farklı değişiklik kimliğiyle (cihaz veritabanı geri yüklendi): yeniden yürütülmez
        $again = $this->push([['id' => (string) Str::uuid7()] + $commands[0]]);
        $this->assertSame('duplicate', $again['results'][0]['status']);
        $this->assertSame(1, DB::table('refunds')->count());
    }

    public function test_refund_of_payment_voided_on_server_goes_to_reconciliation_and_keeps_cash_right(): void
    {
        $s = $this->student('Iade');
        $payment = app(PaymentService::class)->collect($s, ['finance_account_id' => $this->cash, 'method' => 'cash', 'amount' => '500', 'overpayment' => 'credit']);
        [$commands, $local] = $this->captureLocal(fn () => app(RefundService::class)->refund($payment, [
            'amount' => '500', 'finance_account_id' => $this->cash, 'method' => 'cash', 'reason' => 'Kayıt iptali iadesi',
        ])->uuid);

        // Web'de aynı tahsilat bu arada iptal edildi
        app(PaymentService::class)->void($payment->fresh(), 'Web tarafında iptal');

        $r = $this->push($commands);
        $this->assertSame('conflict', $r['results'][0]['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, DB::table('refunds')->count(), 'iptal edilmiş tahsilattan iade yok');
        $this->assertContains($local, $r['results'][0]['unused_uuids']['refunds'] ?? []);
        $entry = FinanceEntry::query()->firstOrFail();
        $this->assertSame('500.00', (string) $entry->amount, 'fiilen ödenen para gider olarak kasadan düşer');
        $conflict = SyncConflict::query()->where('kind', 'finance')->firstOrFail();
        $this->assertStringContainsString('Mükerrer iade farkı', $conflict->note);
        $this->assertTrue(SyncConflict::query()->where('kind', 'finance')->where('note', 'like', '%kasası%')->exists(), 'kasa eksiye düştü notu');
        $this->assertSame('-500.00', (string) FinanceAccount::query()->find($this->cash)->balance);
        $this->assertNoDrift();
    }

    public function test_expense_replay_with_insufficient_server_cash_is_accepted_with_note(): void
    {
        $s = $this->student('Kasa');
        app(PaymentService::class)->collect($s, ['finance_account_id' => $this->cash, 'method' => 'cash', 'amount' => '1000', 'overpayment' => 'credit']);
        $cat = \App\Models\FinanceCategory::query()->create(['direction' => 'expense', 'code' => 'misc', 'name' => 'Çeşitli']);
        [$commands] = $this->captureLocal(fn () => app(FinanceEntryService::class)->record(['direction' => 'expense', 'finance_category_id' => $cat->id,
            'finance_account_id' => $this->cash, 'amount' => '300', 'entry_date' => today()->toDateString(), 'description' => 'Çevrimdışı gider']));
        // Web'de kasa neredeyse boşaltıldı
        app(FinanceEntryService::class)->record(['direction' => 'expense', 'finance_category_id' => $cat->id, 'finance_account_id' => $this->cash,
            'amount' => '900', 'entry_date' => today()->toDateString(), 'description' => 'Web gideri']);

        // Web'de aynı işlem reddedilir…
        try {
            app(FinanceEntryService::class)->record(['direction' => 'expense', 'finance_category_id' => $cat->id, 'finance_account_id' => $this->cash,
                'amount' => '300', 'entry_date' => today()->toDateString(), 'description' => 'x']);
            $this->fail('web tarafında kasa eksiye düşmemeli');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('insufficient_cash', $e->errorCode);
        }
        // …cihazdan gelen (fiilen yapılmış) işlem kabul edilir ve mutabakata düşer
        $r = $this->push($commands);
        $this->assertSame('conflict', $r['results'][0]['status']);
        $this->assertSame('-200.00', (string) FinanceAccount::query()->find($this->cash)->balance);
        $this->assertStringContainsString('-200.00', SyncConflict::query()->where('kind', 'finance')->value('note'));
        $this->assertNoDrift();
    }

    public function test_promissory_note_number_conflict_keeps_server_note_and_reports(): void
    {
        $s = $this->student('Senet');
        $enrollment = $this->enroll($s, '2000', 2);
        $inst = $enrollment->installments()->orderBy('sequence')->first();
        [$commands, $deviceNote] = $this->captureLocal(function () use ($inst) {
            $n = app(PromissoryNoteService::class)->prepare(collect([$inst]))->first();

            return [$n->uuid, $n->note_no];
        });
        $serverNote = app(PromissoryNoteService::class)->prepare(collect([$inst->fresh()]))->first();
        $this->assertNotSame($deviceNote[1], $serverNote->note_no);

        $r = $this->push($commands);
        $this->assertSame('conflict', $r['results'][0]['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame([$deviceNote[0]], $r['results'][0]['unused_uuids']['promissory_notes']);
        $this->assertSame(1, PromissoryNote::query()->count());
        $this->assertStringContainsString($deviceNote[1], SyncConflict::query()->value('note'));
    }

    public function test_restructure_command_decodes_row_ids_and_rejects_on_server_rule(): void
    {
        $s = $this->student('Plan');
        $enrollment = $this->enroll($s, '3000', 3);
        $rows = $enrollment->installments()->orderBy('sequence')->get()->map(fn ($i) => ['id' => $i->id, 'due_date' => $i->due_date->toDateString(), 'amount' => '1000.00'])->all();
        $rows[2]['amount'] = '500.00';
        $rows[] = ['id' => null, 'due_date' => today()->addMonths(5)->toDateString(), 'amount' => '500.00'];
        [$commands] = $this->captureLocal(fn () => app(InstallmentPlanService::class)->restructure($enrollment, $rows));
        $this->assertTrue(Str::isUuid($commands[0]['args']['rows'][0]['id']));
        $this->assertNull($commands[0]['args']['rows'][3]['id']);
        $this->assertSame([$enrollment->installments()->orderBy('sequence')->get()[0]->uuid], [$commands[0]['args']['rows'][0]['id']]);

        // Çevrimdışıyken üçüncü taksit web'de ödendi → sunucu kuralı planı reddeder, cihaz geri alır
        $third = $enrollment->installments()->orderBy('sequence')->get()[2];
        app(PaymentService::class)->collect($s, ['finance_account_id' => $this->cash, 'method' => 'cash', 'amount' => '1000', 'installment_ids' => [$third->id]]);
        $r = $this->push($commands);
        $this->assertSame('rejected', $r['results'][0]['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, $enrollment->installments()->count());
        $this->assertTrue(SyncConflict::query()->where('kind', 'rejected')->exists());
        // Codec joker yolu
        $this->assertSame(['rows.0.id', 'rows.1.id'], CommandCodec::expand(['rows' => [['id' => 1], ['id' => 2]]], 'rows.*.id'));
    }

    public function test_unsupported_offline_finance_and_user_writes_fail_with_clear_message(): void
    {
        $this->useNode('local');
        foreach ([
            fn () => app(AccountService::class)->adjust(FinanceAccount::query()->find($this->cash), '10', 'Sayım farkı testi'),
            fn () => app(ReconciliationService::class)->mark([1], today()->toDateString(), null),
            fn () => $this->admin->fresh()->forceFill(['name' => 'Yeni Ad'])->save(),
            fn () => $this->admin->fresh()->forceFill(['password' => 'YeniParola2026'])->save(),
            fn () => $this->admin->fresh()->delete(),
            fn () => User::query()->create(['branch_id' => $this->branch->id, 'name' => 'X', 'username' => 'x1', 'user_type' => 'staff', 'password' => 'Parola123!', 'is_active' => true]),
        ] as $i => $fn) {
            try {
                $fn();
                $this->fail("#$i engellenmeliydi");
            } catch (\App\Exceptions\BusinessRuleException $e) {
                $this->assertSame('offline_not_supported', $e->errorCode, "#$i");
            }
        }
        // Giriş zamanı (düğüme özel) yazılabilir
        $this->admin->fresh()->forceFill(['last_login_at' => now(), 'last_login_ip' => '127.0.0.1'])->save();
        $this->assertSame('Mudur1', $this->admin->fresh()->name);
        $this->useNode('server');
    }

    // ================================================================== dosyalar

    public function test_file_manifest_download_and_upload_are_scoped_and_verified(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $s = $this->student('Foto');
        Storage::disk('public')->put('students/1-abc.webp', 'FOTO-ICERIK');
        $s->forceFill(['photo_path' => 'students/1-abc.webp'])->save();
        Storage::disk('local')->put('discipline/9/ek.pdf', 'DISIPLIN');
        $incidentDoc = \App\Models\Document::query()->create(['documentable_type' => 'discipline_incident', 'documentable_id' => 1, 'category' => 'discipline',
            'title' => 'Ek', 'disk' => 'local', 'path' => 'discipline/9/ek.pdf', 'mime_type' => 'application/pdf', 'size' => 8, 'visibility' => 'staff']);
        $this->assertArrayHasKey('photo_path', app(RowCodec::class)->encode('students', $s->getAttributes()), 'fotoğraf yolu satırla eşitlenir');
        $this->assertTrue(SyncRegistry::get('documents')->isPushable(), 'belgeler cihazdan gelebilir');

        $stats = app(FileIndex::class)->refresh();
        $this->assertSame(2, $stats['hashed']);
        $sha = hash('sha256', 'FOTO-ICERIK');
        $manifest = app(FileSyncService::class)->manifest($this->device, $this->admin, null, 100);
        $this->assertEqualsCanonicalizing(['students/1-abc.webp', 'discipline/9/ek.pdf'], array_column($manifest['files'], 'path'));
        $this->assertSame($sha, collect($manifest['files'])->firstWhere('path', 'students/1-abc.webp')['sha256']);
        // İmleçten sonra değişiklik yok
        $this->assertSame([], app(FileSyncService::class)->manifest($this->device, $this->admin, $manifest['next'], 100)['files']);

        // Yetki: disiplin yetkisi olmayan cihaz kullanıcısı eki görmez / indiremez
        $limited = $this->makeUser('rehber1', 'staff');
        Role::findOrCreate('sinirli', 'web')->syncPermissions(['students.view', 'documents.view', 'sync.use']);
        $limited->assignRole('sinirli');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $limitedDevice = $this->makeDevice($limited);
        $this->assertSame(['students/1-abc.webp'], array_column(app(FileSyncService::class)->manifest($limitedDevice, $limited->fresh(), null, 100)['files'], 'path'));
        // Şube: başka şubenin cihazı hiçbir dosyayı görmez
        $other = Branch::query()->create(['code' => 'NKS', 'name' => 'Niksar']);
        $otherUser = $this->makeUser('niksar1', 'staff', 'yonetici', $other->id);
        $this->assertSame([], app(FileSyncService::class)->manifest($this->makeDevice($otherUser, $other->id), $otherUser, null, 100)['files']);

        // HTTP: indirme yalnız cihaz jetonuyla
        $this->app['auth']->forgetGuards();
        $res = $this->withToken($this->tokens[$this->device->id])->get('/api/v1/sync/files/'.$sha);
        $res->assertOk();
        $this->assertSame('FOTO-ICERIK', file_get_contents($res->baseResponse->getFile()->getPathname()));
        $this->assertSame($sha, $res->headers->get('X-Content-SHA256'));
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokens[$limitedDevice->id])->getJson('/api/v1/sync/files/'.hash('sha256', 'DISIPLIN'))->assertStatus(404);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokens[$this->device->id])->getJson('/api/v1/sync/files/'.str_repeat('a', 64))->assertStatus(404);
        $plain = $this->admin->createToken('mobil', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/sync/files/manifest')->assertStatus(403);

        // Yükleme: cihazda eklenen belge (satır önce gönderildi, dosya sonra)
        \App\Models\Document::query()->create(['documentable_type' => 'teacher', 'documentable_id' => 1, 'category' => 'identity',
            'title' => 'Kimlik', 'disk' => 'local', 'path' => 'teachers/1/documents/kimlik.pdf', 'mime_type' => 'application/pdf', 'size' => 5, 'visibility' => 'staff']);
        $upload = fn (string $path, string $content, ?string $hash = null, string $disk = 'local') => $this->withToken($this->tokens[$this->device->id])
            ->post('/api/v1/sync/files', ['file' => UploadedFile::fake()->createWithContent(basename($path), $content), 'disk' => $disk, 'path' => $path, 'sha256' => $hash ?? hash('sha256', $content)], ['Accept' => 'application/json']);
        $this->app['auth']->forgetGuards();
        $upload('teachers/1/documents/kimlik.pdf', 'KIMLIK')->assertStatus(201)->assertJsonPath('status', 'stored');
        $this->assertSame('KIMLIK', Storage::disk('local')->get('teachers/1/documents/kimlik.pdf'));
        $this->assertSame(hash('sha256', 'KIMLIK'), DB::table('sync_files')->where('path', 'teachers/1/documents/kimlik.pdf')->value('sha256'));
        $this->app['auth']->forgetGuards();
        $upload('teachers/1/documents/kimlik.pdf', 'KIMLIK')->assertOk()->assertJsonPath('status', 'duplicate');
        $this->app['auth']->forgetGuards();
        $upload('teachers/1/documents/kimlik.pdf', 'BASKA')->assertStatus(409)->assertJsonPath('error_code', 'file_conflict');
        $this->assertSame('KIMLIK', Storage::disk('local')->get('teachers/1/documents/kimlik.pdf'), 'sunucu dosyasının üzerine yazılmaz');
        $this->app['auth']->forgetGuards();
        $upload('teachers/1/documents/yok.pdf', 'X')->assertStatus(404)->assertJsonPath('error_code', 'owner_missing');
        $this->app['auth']->forgetGuards();
        $upload('teachers/1/documents/kimlik.pdf', 'KIMLIK', str_repeat('b', 64))->assertStatus(422)->assertJsonPath('error_code', 'hash_mismatch');
        $this->app['auth']->forgetGuards();
        $upload('../.env', 'X')->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $upload('students/2-x.php', 'X', null, 'public')->assertStatus(422)->assertJsonPath('error_code', 'invalid_path');
        config(['sync.file_max_mb' => 1]);
        $this->app['auth']->forgetGuards();
        $upload('teachers/1/documents/buyuk.pdf', str_repeat('A', 1024 * 1024 + 10))->assertStatus(422);
    }

    public function test_local_file_sync_downloads_uploads_and_retries(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        config(['sync.server_url' => 'https://sunucu.test', 'sync.device_token' => 'cihaz-jetonu']);
        $s = $this->student('Yerel');
        $content = 'SUNUCU-FOTO';
        $sha = hash('sha256', $content);
        // Yerelde oluşturulmuş belge (yüklenecek) + sunucudan gelecek fotoğraf
        Storage::disk('local')->put('homework/5/odev.pdf', 'ODEV');
        \App\Models\Document::query()->create(['documentable_type' => 'homework', 'documentable_id' => 5, 'category' => 'homework',
            'title' => 'Ödev', 'disk' => 'local', 'path' => 'homework/5/odev.pdf', 'mime_type' => 'application/pdf', 'size' => 4, 'visibility' => 'private']);
        DB::table('students')->where('id', $s->id)->update(['photo_path' => 'students/77-srv.webp']);

        $downloads = 0;
        Http::fake(function ($request) use ($content, $sha, &$downloads) {
            $url = $request->url();
            if (str_contains($url, '/sync/files/manifest')) {
                return Http::response(['files' => [['disk' => 'public', 'path' => 'students/77-srv.webp', 'sha256' => $sha, 'size' => strlen($content),
                    'mime' => 'image/webp', 'owner_table' => 'students', 'owner_uuid' => null, 'status' => 'present']], 'next' => '2026-09-17 10:00:00.000|1', 'more' => false]);
            }
            if (str_contains($url, '/sync/files/'.$sha)) {
                $downloads++;

                return $downloads === 1 ? Http::response(['message' => 'Geçici hata'], 404) : Http::response($content, 200);
            }
            if (str_ends_with($url, '/sync/files') && $request->method() === 'POST') {
                return Http::response(['status' => 'stored'], 201);
            }

            return Http::response([], 404);
        });

        $sync = app(LocalFileSync::class);
        $first = $sync->run();
        $this->assertSame(1, $first['uploaded'], json_encode($first));
        $this->assertSame(0, $first['downloaded']);
        $this->assertSame(1, $first['failed'], 'ilk indirme başarısız → kuyrukta kalır');
        $row = DB::table('sync_files')->where('path', 'students/77-srv.webp')->first();
        $this->assertSame('download', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotNull($row->next_attempt_at);
        Http::assertSent(function ($r) {
            $parts = collect($r->data())->keyBy('name');

            return $r->method() === 'POST' && str_ends_with($r->url(), '/sync/files')
                && ($parts['path']['contents'] ?? null) === 'homework/5/odev.pdf' && ($parts['sha256']['contents'] ?? null) === hash('sha256', 'ODEV');
        });

        // Geri çekilme süresi dolmadan yeniden denenmez
        $this->assertSame(0, $sync->run()['downloaded']);
        DB::table('sync_files')->where('id', $row->id)->update(['next_attempt_at' => now()->subMinute()->format('Y-m-d H:i:s')]);
        $third = $sync->run();
        $this->assertSame(1, $third['downloaded']);
        $this->assertSame($content, Storage::disk('public')->get('students/77-srv.webp'));
        $this->assertSame('present', DB::table('sync_files')->where('id', $row->id)->value('status'));
        $this->assertSame(0, $third['pending']);
        $this->assertSame('present', DB::table('sync_files')->where('path', 'homework/5/odev.pdf')->value('status'));
    }

    // ================================================================== oturum + parola

    public function test_bearer_requests_do_not_create_sessions_but_cookie_login_does(): void
    {
        config(['session.driver' => 'database']);
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');
        $plain = $this->admin->createToken('mobil', ['*'])->plainTextToken;
        $before = DB::table('sessions')->count();
        for ($i = 0; $i < 3; $i++) {
            $this->app['auth']->forgetGuards();
            $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk();
        }
        $this->assertSame($before, DB::table('sessions')->count(), 'Bearer isteği oturum satırı açmaz');

        // Bearer ile parola değişimi oturumsuz çalışır ve mevcut jeton açık kalır
        $other = $this->admin->createToken('eski-telefon', ['*']);
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/auth/change-password', ['current_password' => 'Parola123!', 'password' => 'YeniParola2026', 'password_confirmation' => 'YeniParola2026'])
            ->assertOk();
        $this->assertNull(DB::table('personal_access_tokens')->where('id', $other->accessToken->id)->first(), 'diğer jetonlar kapanır');
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk();

        // Web paneli (çerez) oturumu bozulmadı
        $this->app['auth']->forgetGuards();
        $login = $this->withoutToken()->postJson('/api/v1/auth/login', ['login' => 'mudur1', 'password' => 'YeniParola2026']);
        $login->assertOk();
        $this->assertSame($before + 1, DB::table('sessions')->count(), 'çerezli giriş oturum açar');
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertFalse(\App\Http\Middleware\StartSessionUnlessBearer::isStatelessBearer(
            \Illuminate\Http\Request::create('/api/v1/auth/me', 'GET', [], [config('session.cookie') => 'x'], [], ['HTTP_AUTHORIZATION' => 'Bearer y'])
        ), 'çerez + Bearer → oturum kullanılır');
    }

    public function test_device_password_change_is_online_only_and_verified_on_server(): void
    {
        $portal = $this->makeUser('20269999', 'student', 'ogrenci');
        $call = function (array $body) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($this->tokens[$this->device->id])->postJson('/api/v1/sync/password', $body);
        };
        $call(['user' => $this->admin->uuid, 'current_password' => 'Yanlis123!', 'password' => 'YeniParola2026'])->assertStatus(422);
        $call(['user' => $portal->uuid, 'current_password' => 'Parola123!', 'password' => 'YeniParola2026'])->assertStatus(403);
        $call(['user' => $this->admin->uuid, 'current_password' => 'Parola123!', 'password' => 'kisa'])->assertStatus(422);
        $res = $call(['user' => $this->admin->uuid, 'current_password' => 'Parola123!', 'password' => 'YeniParola2026'])->assertOk();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('YeniParola2026', $res->json('password_hash')));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('YeniParola2026', $this->admin->fresh()->password));
        $this->assertTrue(DB::table('personal_access_tokens')->where('id', $this->device->token_id)->exists(), 'cihaz jetonu açık kalır');

        // Yerel vekil: çevrimdışıysa kuyruğa almaz, açık hata verir
        config(['sync.server_url' => 'https://sunucu.test', 'sync.device_token' => 'x']);
        $online = false;
        $hash = \Illuminate\Support\Facades\Hash::make('DahaYeni2026x');
        Http::fake(function ($request) use (&$online, $hash) {
            return $online
                ? Http::response(['message' => 'ok', 'password_hash' => $hash, 'password_changed_at' => now()->toIso8601String()])
                : (Http::failedConnection())($request);
        });
        $this->useNode('local');
        try {
            app(\App\Sync\Local\LocalPasswordProxy::class)->change($this->admin->fresh(), 'YeniParola2026', 'DahaYeni2026x');
            $this->fail('çevrimdışı parola değişimi engellenmeliydi');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('offline_not_supported', $e->errorCode);
        }
        $this->assertSame(0, DB::table('sync_changes')->where('table_name', 'users')->where('op', 'command')->count());

        // Çevrimiçi: sunucunun verdiği özet yerele yazılır (guard'a takılmadan)
        $online = true;
        app(\App\Sync\Local\LocalPasswordProxy::class)->change($this->admin->fresh(), 'YeniParola2026', 'DahaYeni2026x');
        $this->assertSame($hash, DB::table('users')->where('id', $this->admin->id)->value('password'));
        $this->useNode('server');
    }

    // ================================================================== SQLite uyumluluğu (yerel rapor ekranları)

    public function test_sqlite_compat_rewrites_mysql_double_quoted_literals_only(): void
    {
        $this->assertSame("SELECT SUM(a.status = 'absent') AS absent", SqliteCompatConnection::rewrite('SELECT SUM(a.status = "absent") AS absent'));
        $this->assertSame("WHERE status <> 'cancelled' AND a.status IN ('excused', 'medical')",
            SqliteCompatConnection::rewrite('WHERE status <> "cancelled" AND a.status IN ("excused", "medical")'));
        // Sorgu oluşturucunun tanımlayıcılarına dokunulmaz
        foreach (['select * from "students" where "students"."id" = ?', 'select * from "a" where "a"."x" = "b"."y"', 'where "status" = "absent"'] as $sql) {
            $this->assertSame($sql, SqliteCompatConnection::rewrite($sql));
        }
        // Gerçek sorgu: takma adla aynı adlı değer (MySQL'de metin, SQLite'ta önceden hata)
        DB::table('students')->insert(['branch_id' => $this->branch->id, 'student_no' => '1', 'first_name' => 'A', 'last_name' => 'B', 'full_name' => 'A B', 'status' => 'absent', 'created_at' => now(), 'updated_at' => now()]);
        $pdo = DB::connection()->getPdo();
        \App\Sync\Local\SqliteCompat::register($pdo);
        $row = $pdo->query(SqliteCompatConnection::rewrite('SELECT SUM(status = "absent") AS absent FROM students'))->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(1, $row['absent']);
    }
}
