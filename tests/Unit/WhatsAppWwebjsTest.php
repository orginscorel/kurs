<?php

namespace Tests\Unit;

use App\Jobs\SendOutboundMessage;
use App\Models\Branch;
use App\Models\Guardian;
use App\Models\Integration;
use App\Models\OutboundMessage;
use App\Models\Student;
use App\Models\User;
use App\Services\Messaging\Providers\WhatsAppWwebjsProvider;
use App\Services\Messaging\Whatsapp\WwebjsClient;
use App\Services\Notifications\EventNotificationService;
use App\Support\BranchContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * WhatsApp QR (kalıcı oturum) sağlayıcısı: WWebJS köprü sözleşmesi, gerçek gönderim yalnız bağlıyken,
 * ve anti-ban (gecikme + günlük limit + opt-out). Gerçek bota HİÇ istek çıkmaz (Http::fake + preventStray).
 */
class WhatsAppWwebjsTest extends TestCase
{
    private Branch $branch;

    private User $admin;

    private const CFG = ['base_url' => 'http://bot.local:3000', 'api_key' => 'secret-token', 'session_id' => 'kurs'];

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

    // -------------------------------------------------------- İstemci sözleşmesi

    public function test_chat_id_normalizes_turkish_mobile(): void
    {
        $client = new WwebjsClient(self::CFG);
        $this->assertSame('905321112233@c.us', $client->chatId('0532 111 22 33'));
        $this->assertSame('905321112233@c.us', $client->chatId('+90 532 111 22 33'));
        $this->assertSame('120363@g.us', $client->chatId('120363@g.us')); // grup ID korunur
    }

    public function test_test_connection_reports_connected(): void
    {
        Http::fake(['*/session/status/*' => Http::response(['success' => true, 'state' => 'CONNECTED', 'message' => 'session_connected', 'id' => '905320000001'], 200)]);
        $res = (new WhatsAppWwebjsProvider)->testConnection(self::CFG);
        $this->assertTrue($res->success);
    }

    public function test_test_connection_fails_when_not_connected(): void
    {
        Http::fake(['*/session/status/*' => Http::response(['success' => true, 'state' => 'QRCODE'], 200)]);
        $res = (new WhatsAppWwebjsProvider)->testConnection(self::CFG);
        $this->assertFalse($res->success);
        $this->assertStringContainsString('QR', $res->message);
    }

    public function test_test_connection_fails_when_unreachable(): void
    {
        Http::fake(['*/session/status/*' => Http::response('', 500)]);
        $res = (new WhatsAppWwebjsProvider)->testConnection(self::CFG);
        $this->assertFalse($res->success);
    }

    public function test_send_posts_expected_wwebjs_payload(): void
    {
        Http::fake(['*/client/sendMessage/*' => Http::response(['success' => true, 'messageId' => 'MSG-1'], 200)]);
        $msg = (new OutboundMessage)->forceFill(['branch_id' => $this->branch->id, 'channel' => 'whatsapp', 'to' => '5321112233', 'body' => 'Merhaba']);

        $res = (new WhatsAppWwebjsProvider)->send($msg, self::CFG);

        $this->assertTrue($res->success);
        $this->assertSame('MSG-1', $res->providerMessageId);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/client/sendMessage/kurs')
                && $request['chatId'] === '905321112233@c.us'
                && $request['contentType'] === 'string'
                && $request['content'] === 'Merhaba'
                && $request->hasHeader('X-API-Token', 'secret-token');
        });
    }

    // -------------------------------------------------------- Anti-ban / gönderim akışı

    private function integration(array $extra = []): Integration
    {
        $i = new Integration;
        $i->forceFill(['branch_id' => $this->branch->id, 'kind' => 'whatsapp', 'provider' => 'wwebjs', 'status' => 'connected', 'is_enabled' => true]);
        $i->setConfig(array_merge(self::CFG, $extra)); // model ile aynı şifreleme (encryptString) — config() okuyabilsin
        $i->save();

        return $i;
    }

    private function student(string $phone): Student
    {
        $s = new Student;
        $s->forceFill(['branch_id' => $this->branch->id, 'student_no' => (string) random_int(100000, 999999),
            'first_name' => 'Ali', 'last_name' => 'Yılmaz', 'phone' => $phone, 'status' => 'active'])->save();

        return $s->refresh();
    }

    public function test_opt_out_number_is_cancelled_not_sent(): void
    {
        Bus::fake();
        $this->integration(['opt_out' => '0532 111 22 33']);
        $s = $this->student('05321112233');

        $batch = app(EventNotificationService::class)->buildDrafts('coaching.session', [
            'student_ids' => [$s->id], 'audiences' => ['student'],
            'vars' => ['koc_adi' => 'Ayşe', 'tarih' => '25.09.2026', 'saat' => '15:00', 'konu' => 'Analiz'],
        ], $this->admin->id);

        app(EventNotificationService::class)->approve($batch, $this->admin->id);

        $msg = OutboundMessage::query()->where('batch_id', $batch->id)->where('audience', 'student')->first();
        $this->assertSame('cancelled', $msg->status);
        $this->assertStringContainsString('opt-out', mb_strtolower($msg->error ?? ''));
        Bus::assertNotDispatched(SendOutboundMessage::class);
    }

    public function test_daily_limit_overflow_is_failed(): void
    {
        Bus::fake();
        $this->integration(['daily_limit' => 1]);
        $a = $this->student('05321112201');
        $b = $this->student('05321112202');

        $batch = app(EventNotificationService::class)->buildDrafts('coaching.session', [
            'student_ids' => [$a->id, $b->id], 'audiences' => ['student'],
            'vars' => ['koc_adi' => 'Ayşe', 'tarih' => '25.09.2026', 'saat' => '15:00', 'konu' => 'Analiz'],
        ], $this->admin->id);

        app(EventNotificationService::class)->approve($batch, $this->admin->id);

        $statuses = OutboundMessage::query()->where('batch_id', $batch->id)->where('audience', 'student')->pluck('status')->sort()->values()->all();
        $this->assertContains('queued', $statuses);
        $this->assertContains('failed', $statuses);
        Bus::assertDispatchedTimes(SendOutboundMessage::class, 1); // günlük limit=1 → yalnız bir iş
    }
}
