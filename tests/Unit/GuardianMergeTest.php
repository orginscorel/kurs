<?php

namespace Tests\Unit;

use App\Exceptions\BusinessRuleException;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Services\Guardians\GuardianMergeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Mükerrer veli birleştirme: öğrenci bağları, finansal referanslar, mesaj geçmişi, portal hesabı,
 * tek transaction (hata olursa hiçbir şey değişmez), soft delete ve denetim kaydı.
 * Bellek içi SQLite üzerinde asgari şema kurulur (canlı veritabanına ASLA dokunmaz).
 */
class GuardianMergeTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('name');
            $t->string('username', 60)->unique();
            $t->string('email')->nullable()->unique();
            $t->string('phone', 30)->nullable();
            $t->string('user_type', 20)->default('staff');
            $t->string('password');
            $t->text('initial_password')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->timestamp('password_changed_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('student_no');
            $t->string('first_name');
            $t->string('last_name');
            $t->string('full_name')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('guardians', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id')->nullable()->unique();
            $t->string('first_name');
            $t->string('last_name');
            $t->text('national_id_encrypted')->nullable();
            $t->string('national_id_hash')->nullable();
            $t->string('national_id_last4')->nullable();
            $t->string('phone')->nullable();
            $t->string('whatsapp_phone')->nullable();
            $t->string('email')->nullable();
            $t->string('occupation')->nullable();
            $t->string('address')->nullable();
            $t->string('notes', 2000)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('guardian_student', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('guardian_id');
            $t->unsignedBigInteger('student_id');
            $t->string('relationship')->default('parent');
            $t->boolean('is_primary')->default(false);
            $t->boolean('is_financially_responsible')->default(false);
            $t->boolean('receives_notifications')->default(true);
            $t->timestamps();
            $t->unique(['guardian_id', 'student_id']);
        });
        Schema::create('enrollments', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('student_id'), $t->unsignedBigInteger('financial_guardian_id')->nullable()]);
        Schema::create('payments', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('student_id'), $t->unsignedBigInteger('guardian_id')->nullable(), $t->decimal('amount', 14, 2)]);
        Schema::create('promissory_notes', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('guardian_id')->nullable(), $t->string('debtor_name')]);
        Schema::create('collection_notes', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('student_id'), $t->unsignedBigInteger('guardian_id')->nullable()]);
        Schema::create('contact_requests', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('guardian_id')->nullable(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('subject')]);
        Schema::create('invoices', fn (Blueprint $t) => [$t->id(), $t->string('buyer_type'), $t->unsignedBigInteger('buyer_id')->nullable(), $t->string('buyer_name')]);
        Schema::create('journal_lines', fn (Blueprint $t) => [$t->id(), $t->string('partner_type')->nullable(), $t->unsignedBigInteger('partner_id')->nullable(), $t->string('partner_name')->nullable()]);
        Schema::create('outbound_messages', fn (Blueprint $t) => [$t->id(), $t->string('recipient_type')->nullable(), $t->unsignedBigInteger('recipient_id')->nullable(), $t->string('body')]);
        Schema::create('communication_consents', function (Blueprint $t) {
            $t->id();
            $t->string('consentable_type');
            $t->unsignedBigInteger('consentable_id');
            $t->string('channel');
            $t->string('purpose');
            $t->boolean('granted');
            $t->string('source')->nullable();
            $t->timestamp('recorded_at');
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->unique(['consentable_type', 'consentable_id', 'channel', 'purpose']);
        });
        Schema::create('taggables', fn (Blueprint $t) => [$t->unsignedBigInteger('tag_id'), $t->string('taggable_type'), $t->unsignedBigInteger('taggable_id'), $t->primary(['tag_id', 'taggable_type', 'taggable_id'])]);
        Schema::create('app_notifications', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('user_id'), $t->string('type'), $t->string('title'), $t->text('body')->nullable(), $t->string('action_url')->nullable(), $t->text('data')->nullable(), $t->timestamp('read_at')->nullable(), $t->timestamp('created_at')->nullable()]);
        Schema::create('sessions', fn (Blueprint $t) => [$t->string('id')->primary(), $t->unsignedBigInteger('user_id')->nullable(), $t->text('payload')->nullable(), $t->integer('last_activity')->default(0)]);
        Schema::create('personal_access_tokens', fn (Blueprint $t) => [$t->id(), $t->string('tokenable_type'), $t->unsignedBigInteger('tokenable_id'), $t->string('name'), $t->string('token', 64)->unique(), $t->timestamps()]);
        Schema::create('roles', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('guard_name'), $t->timestamps()]);
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('description', 500);
            $t->text('changes')->nullable();
            $t->string('ip_address')->nullable();
            $t->string('user_agent')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    // ---------------------------------------------------------------- yardımcılar

    private function student(string $name = 'Öğrenci'): Student
    {
        $this->seq++;
        $s = new Student;
        $s->forceFill(['branch_id' => 1, 'student_no' => (string) (2026900 + $this->seq), 'first_name' => $name.$this->seq, 'last_name' => 'Test', 'full_name' => $name.$this->seq.' Test', 'status' => 'active'])->save();

        return $s;
    }

    private function guardian(?string $phone, array $children = [], array $extra = []): Guardian
    {
        $g = new Guardian;
        $g->forceFill(['branch_id' => 1, 'first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'phone' => $phone] + $extra)->save();
        foreach ($children as $i => [$child, $pivot]) {
            DB::table('guardian_student')->insert(['guardian_id' => $g->id, 'student_id' => $child->id] + $pivot);
        }

        return $g;
    }

    private function user(string $username, bool $active = true): User
    {
        $u = new User;
        $u->forceFill(['name' => 'Veli', 'username' => $username, 'user_type' => User::TYPE_GUARDIAN, 'password' => 'x', 'is_active' => $active])->save();

        return $u;
    }

    private function service(): GuardianMergeService
    {
        return app(GuardianMergeService::class);
    }

    // ---------------------------------------------------------------- testler

    public function test_duplicates_are_grouped_by_normalized_phone_including_whatsapp(): void
    {
        $a = $this->guardian('0532 111 22 33');
        $b = $this->guardian('905321112233');
        $c = $this->guardian(null, [], ['whatsapp_phone' => '+90 (532) 111-2233']);
        $d = $this->guardian('05559998877');

        $groups = $this->service()->duplicateGroups();
        $this->assertCount(1, $groups);
        $this->assertSame([$a->id, $b->id, $c->id], $groups[0]['guardian_ids']);
        $this->assertSame('5321112233', $groups[0]['key']);

        $map = $this->service()->duplicateMap();
        $this->assertSame([$b->id, $c->id], $map[$a->id]);
        $this->assertArrayNotHasKey($d->id, $map);
    }

    public function test_preview_writes_nothing_and_reports_plan(): void
    {
        $s1 = $this->student();
        $s2 = $this->student();
        $target = $this->guardian('05321112233', [[$s1, ['relationship' => 'parent', 'is_primary' => false]]]);
        $source = $this->guardian('0532 111 22 33', [[$s1, ['relationship' => 'mother', 'is_primary' => true]], [$s2, ['relationship' => 'mother', 'is_primary' => true, 'is_financially_responsible' => true]]], ['email' => 'ayse@example.com']);
        DB::table('payments')->insert(['student_id' => $s2->id, 'guardian_id' => $source->id, 'amount' => 100]);

        $plan = $this->service()->preview($target->id, $source->id);

        $this->assertSame(['merge', 'move'], array_column($plan['students'], 'action'));
        $this->assertSame(1, collect($plan['counts'])->firstWhere('key', 'payments')['count']);
        $this->assertSame(1, $plan['financial_total']);
        $this->assertSame(['email'], array_column($plan['fills'], 'field'));
        $this->assertSame('none', $plan['account']);
        $this->assertSame([], $plan['warnings']);
        $this->assertSame(64, strlen($plan['fingerprint']));
        // Hiçbir şey yazılmadı
        $this->assertSame(2, DB::table('guardian_student')->where('guardian_id', $source->id)->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertNull(Guardian::query()->find($source->id)->deleted_at);
    }

    public function test_merge_moves_links_finance_messages_and_account_then_soft_deletes(): void
    {
        $s1 = $this->student();
        $s2 = $this->student();
        $target = $this->guardian('05321112233', [[$s1, ['relationship' => 'parent', 'is_primary' => false, 'is_financially_responsible' => false]]]);
        $sourceUser = $this->user('05321112233');
        $source = $this->guardian('05321112233', [
            [$s1, ['relationship' => 'mother', 'is_primary' => true, 'is_financially_responsible' => true]],
            [$s2, ['relationship' => 'mother', 'is_primary' => true]],
        ], ['user_id' => $sourceUser->id, 'email' => 'ayse@example.com', 'occupation' => 'Öğretmen', 'notes' => 'Akşam arayın']);

        DB::table('enrollments')->insert(['student_id' => $s2->id, 'financial_guardian_id' => $source->id]);
        DB::table('payments')->insert([['student_id' => $s2->id, 'guardian_id' => $source->id, 'amount' => 100], ['student_id' => $s1->id, 'guardian_id' => $target->id, 'amount' => 50]]);
        DB::table('promissory_notes')->insert(['guardian_id' => $source->id, 'debtor_name' => 'AYŞE YILMAZ (eski)']);
        DB::table('collection_notes')->insert(['student_id' => $s2->id, 'guardian_id' => $source->id]);
        DB::table('contact_requests')->insert(['guardian_id' => $source->id, 'user_id' => $sourceUser->id, 'subject' => 'Görüşme']);
        DB::table('invoices')->insert([['buyer_type' => 'guardian', 'buyer_id' => $source->id, 'buyer_name' => 'Ayşe Y.'], ['buyer_type' => 'institution', 'buyer_id' => $source->id, 'buyer_name' => 'Başka firma']]);
        DB::table('journal_lines')->insert([['partner_type' => 'guardian', 'partner_id' => $source->id, 'partner_name' => 'Ayşe'], ['partner_type' => 'student', 'partner_id' => $source->id, 'partner_name' => 'Öğrenci']]);
        DB::table('outbound_messages')->insert([['recipient_type' => 'guardian', 'recipient_id' => $source->id, 'body' => 'x'], ['recipient_type' => 'teacher', 'recipient_id' => $source->id, 'body' => 'y']]);
        DB::table('communication_consents')->insert([
            ['consentable_type' => 'guardian', 'consentable_id' => $source->id, 'channel' => 'sms', 'purpose' => 'marketing', 'granted' => true, 'source' => 'Kayıt formu', 'recorded_at' => now()],
        ]);
        DB::table('taggables')->insert(['tag_id' => 7, 'taggable_type' => 'guardian', 'taggable_id' => $source->id]);

        $fp = $this->service()->preview($target->id, $source->id)['fingerprint'];
        $result = $this->service()->merge($target->id, $source->id, $fp);

        // Öğrenci bağları: s1'de işaretler birleşti, s2 taşındı, kaynakta bağ kalmadı
        $this->assertSame(0, DB::table('guardian_student')->where('guardian_id', $source->id)->count());
        $l1 = DB::table('guardian_student')->where('guardian_id', $target->id)->where('student_id', $s1->id)->first();
        $this->assertTrue((bool) $l1->is_primary);
        $this->assertTrue((bool) $l1->is_financially_responsible);
        $this->assertSame('mother', $l1->relationship);
        $this->assertTrue(DB::table('guardian_student')->where('guardian_id', $target->id)->where('student_id', $s2->id)->exists());
        $this->assertSame(1, DB::table('guardian_student')->where('student_id', $s1->id)->count());

        // Finansal sorumluluk
        $this->assertSame($target->id, (int) DB::table('enrollments')->value('financial_guardian_id'));
        $this->assertSame(2, DB::table('payments')->where('guardian_id', $target->id)->count());
        $this->assertSame($target->id, (int) DB::table('promissory_notes')->value('guardian_id'));
        $this->assertSame('AYŞE YILMAZ (eski)', DB::table('promissory_notes')->value('debtor_name'), 'belge üzerindeki ad değişmemeli');
        $this->assertSame($target->id, (int) DB::table('collection_notes')->value('guardian_id'));
        $this->assertSame($target->id, (int) DB::table('invoices')->where('buyer_type', 'guardian')->value('buyer_id'));
        $this->assertSame($source->id, (int) DB::table('invoices')->where('buyer_type', 'institution')->value('buyer_id'), 'başka türdeki referansa dokunulmamalı');
        $this->assertSame($target->id, (int) DB::table('journal_lines')->where('partner_type', 'guardian')->value('partner_id'));
        $this->assertSame($source->id, (int) DB::table('journal_lines')->where('partner_type', 'student')->value('partner_id'));

        // Mesaj geçmişi, talepler, izinler, etiketler
        $this->assertSame($target->id, (int) DB::table('outbound_messages')->where('recipient_type', 'guardian')->value('recipient_id'));
        $this->assertSame($source->id, (int) DB::table('outbound_messages')->where('recipient_type', 'teacher')->value('recipient_id'));
        $this->assertSame($target->id, (int) DB::table('contact_requests')->value('guardian_id'));
        $this->assertTrue((bool) DB::table('communication_consents')->where('consentable_id', $target->id)->where('channel', 'sms')->value('granted'));
        $this->assertTrue(DB::table('taggables')->where('taggable_id', $target->id)->where('tag_id', 7)->exists());

        // Portal hesabı hedefe geçti (hedefin hesabı yoktu)
        $target = Guardian::query()->withTrashed()->find($target->id);
        $source = Guardian::query()->withTrashed()->find($source->id);
        $this->assertSame($sourceUser->id, (int) $target->user_id);
        $this->assertNull($source->user_id);
        $this->assertTrue((bool) User::query()->find($sourceUser->id)->is_active);

        // Boş alanlar dolduruldu, not eklendi
        $this->assertSame('ayse@example.com', $target->email);
        $this->assertSame('Öğretmen', $target->occupation);
        $this->assertStringContainsString('Akşam arayın', (string) $target->notes);

        // Kaynak silinmedi, arşivlendi
        $this->assertNotNull($source->deleted_at);
        $this->assertNull(Guardian::query()->find($source->id));
        $this->assertStringContainsString('#'.$target->id, (string) $source->notes);

        // Denetim kaydı
        $log = DB::table('audit_logs')->where('action', 'guardian.merged')->first();
        $this->assertNotNull($log);
        $this->assertSame($target->id, (int) $log->subject_id);
        $changes = json_decode($log->changes, true);
        $this->assertSame($source->id, $changes['source_id']);
        $this->assertSame(1, $changes['moved']['payments']);
        $this->assertSame('move', $changes['account']['plan']);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'guardian.merged_away')->where('subject_id', $source->id)->exists());
        $this->assertSame(1, $result['moved']['promissory_notes']);
    }

    public function test_when_both_have_accounts_source_account_is_closed_and_requests_follow(): void
    {
        $s = $this->student();
        $tu = $this->user('05321112233');
        $su = $this->user('05321112234');
        $target = $this->guardian('05321112233', [[$s, ['is_primary' => true]]], ['user_id' => $tu->id]);
        $source = $this->guardian('05321112233', [], ['user_id' => $su->id]);
        DB::table('contact_requests')->insert(['guardian_id' => $source->id, 'user_id' => $su->id, 'subject' => 'Talep']);
        DB::table('app_notifications')->insert(['user_id' => $su->id, 'type' => 'contact_answer', 'title' => 'Yanıt', 'created_at' => now()]);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $su->id, 'payload' => '', 'last_activity' => 0]);
        DB::table('communication_consents')->insert([
            ['consentable_type' => 'guardian', 'consentable_id' => $target->id, 'channel' => 'sms', 'purpose' => 'marketing', 'granted' => false, 'source' => 'Eski', 'recorded_at' => now()->subDays(5)],
            ['consentable_type' => 'guardian', 'consentable_id' => $source->id, 'channel' => 'sms', 'purpose' => 'marketing', 'granted' => true, 'source' => 'Kayıt formu', 'recorded_at' => now()],
        ]);

        $plan = $this->service()->preview($target->id, $source->id);
        $this->assertSame('deactivate_source', $plan['account']);
        $this->service()->merge($target->id, $source->id, $plan['fingerprint']);

        $this->assertFalse((bool) User::query()->find($su->id)->is_active);
        $this->assertTrue((bool) User::query()->find($tu->id)->is_active);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $su->id)->count());
        $this->assertSame($tu->id, (int) DB::table('contact_requests')->value('user_id'));
        $this->assertSame($tu->id, (int) DB::table('app_notifications')->value('user_id'));
        // Daha yeni onay hedefe yazıldı
        $this->assertTrue((bool) DB::table('communication_consents')->where('consentable_id', $target->id)->where('channel', 'sms')->value('granted'));
        $this->assertSame($tu->id, (int) Guardian::query()->find($target->id)->user_id);
    }

    public function test_stale_preview_is_rejected_and_nothing_changes(): void
    {
        $s = $this->student();
        $target = $this->guardian('05321112233');
        $source = $this->guardian('05321112233', [[$s, ['is_primary' => true]]]);
        $fp = $this->service()->preview($target->id, $source->id)['fingerprint'];

        // Önizlemeden sonra kaynağa yeni tahsilat bağlandı
        DB::table('payments')->insert(['student_id' => $s->id, 'guardian_id' => $source->id, 'amount' => 10]);

        try {
            $this->service()->merge($target->id, $source->id, $fp);
            $this->fail('Bayat önizleme kabul edilmemeliydi.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame(1, DB::table('guardian_student')->where('guardian_id', $source->id)->count());
        $this->assertNull(Guardian::query()->find($source->id)?->deleted_at);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_failure_mid_merge_rolls_back_everything(): void
    {
        $s = $this->student();
        $target = $this->guardian('05321112233');
        $source = $this->guardian('05321112233', [[$s, ['is_primary' => true]]]);
        DB::table('payments')->insert(['student_id' => $s->id, 'guardian_id' => $source->id, 'amount' => 10]);
        $fp = $this->service()->preview($target->id, $source->id)['fingerprint'];

        // Denetim tablosu yoksa işlem sonunda hata verir → tüm taşımalar geri alınmalı
        Schema::drop('audit_logs');
        try {
            $this->service()->merge($target->id, $source->id, $fp);
            $this->fail('Hata bekleniyordu.');
        } catch (\Throwable) {
            // beklenen
        }

        $this->assertSame(1, DB::table('guardian_student')->where('guardian_id', $source->id)->count());
        $this->assertSame($source->id, (int) DB::table('payments')->value('guardian_id'));
        $this->assertNull(DB::table('guardians')->where('id', $source->id)->value('deleted_at'));
    }

    public function test_guards_same_guardian_and_different_national_ids(): void
    {
        $a = $this->guardian('05321112233', [], ['national_id_hash' => str_repeat('a', 64)]);
        $b = $this->guardian('05321112233', [], ['national_id_hash' => str_repeat('b', 64)]);

        try {
            $this->service()->preview($a->id, $a->id);
            $this->fail('Aynı veli kabul edilmemeli.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('merge_same_guardian', $e->errorCode);
        }

        $plan = $this->service()->preview($a->id, $b->id);
        $this->assertTrue($plan['blocking']);
        $this->expectException(BusinessRuleException::class);
        $this->service()->merge($a->id, $b->id, $plan['fingerprint']);
    }

    public function test_routes_require_manage_permission_and_staff(): void
    {
        $find = function (string $method, string $uri): RoutingRoute {
            foreach (Route::getRoutes()->getRoutes() as $r) {
                if ($r->uri() === 'api/v1/'.$uri && in_array($method, $r->methods(), true)) {
                    return $r;
                }
            }
            $this->fail("Rota yok: $method $uri");
        };

        foreach ([['POST', 'guardians-merge'], ['POST', 'guardians-merge/preview']] as [$m, $u]) {
            $mw = $find($m, $u)->gatherMiddleware();
            $this->assertContains('permission:guardians.manage', $mw, $u);
            $this->assertContains('staff', $mw, $u);
        }
        $this->assertContains('permission:guardians.view', $find('GET', 'guardians-duplicates')->gatherMiddleware());
        $obs = $find('GET', 'students/{student}/observations')->gatherMiddleware();
        $this->assertContains('permission:students.view', $obs);
        $this->assertContains('staff', $obs);
    }
}
