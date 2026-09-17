<?php

namespace Tests\Unit;

use App\Models\CommunicationSuppression;
use App\Models\Integration;
use App\Models\MessageCampaign;
use App\Models\MessageCampaignRecipient;
use App\Models\OutboundMessage;
use App\Services\Campaigns\CampaignPlanner;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\CampaignService;
use App\Services\Campaigns\EmailRenderer;
use App\Services\Campaigns\UnsubscribeToken;
use App\Services\Messaging\Sms\Drivers\SimulationGateway;
use App\Support\BranchContext;
use App\Support\Permissions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Toplu gönderim akışı: İYS/izin filtresi, ret listesi, tekrar eden adres, kuyruk parçalama + hız sınırı,
 * abonelikten çıkma ucu ve yetkiler. Bellek içi SQLite (canlı veritabanına ASLA dokunmaz).
 */
class MessageCampaignFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }
        Http::preventStrayRequests();

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('group');
            $t->string('key');
            $t->json('value')->nullable();
            $t->timestamps();
        });
        Schema::create('communication_consents', function (Blueprint $t) {
            $t->id();
            $t->string('consentable_type');
            $t->unsignedBigInteger('consentable_id');
            $t->string('channel');
            $t->string('purpose');
            $t->boolean('granted');
            $t->string('source')->nullable();
            $t->timestamp('recorded_at')->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->unique(['consentable_type', 'consentable_id', 'channel', 'purpose']);
        });
        Schema::create('communication_suppressions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->string('channel');
            $t->string('address');
            $t->string('reason');
            $t->string('source')->nullable();
            $t->string('recipient_type')->nullable();
            $t->unsignedBigInteger('recipient_id')->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->unique(['branch_id', 'channel', 'address']);
        });
        Schema::create('integrations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('kind');
            $t->string('provider');
            $t->text('config_encrypted')->nullable();
            $t->string('status')->default('disconnected');
            $t->string('last_error')->nullable();
            $t->timestamp('last_checked_at')->nullable();
            $t->boolean('is_enabled')->default(false);
            $t->timestamps();
        });
        Schema::create('message_campaigns', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->string('name');
            $t->json('channels');
            $t->boolean('is_commercial')->default(false);
            $t->json('audience');
            $t->json('options')->nullable();
            $t->text('sms_body')->nullable();
            $t->string('email_subject')->nullable();
            $t->text('email_body')->nullable();
            $t->string('status')->default('draft');
            $t->dateTime('scheduled_at')->nullable();
            $t->json('estimate')->nullable();
            $t->unsignedInteger('recipients_total')->default(0);
            foreach (['created_by', 'approved_by'] as $c) {
                $t->unsignedBigInteger($c)->nullable();
            }
            foreach (['approved_at', 'started_at', 'completed_at', 'cancelled_at'] as $c) {
                $t->timestamp($c)->nullable();
            }
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('message_campaign_recipients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('branch_id');
            $t->string('channel');
            $t->string('recipient_type')->nullable();
            $t->unsignedBigInteger('recipient_id')->nullable();
            $t->unsignedBigInteger('student_id')->nullable();
            $t->string('group');
            $t->string('name')->nullable();
            $t->string('to')->nullable();
            $t->json('vars')->nullable();
            $t->string('status')->default('pending');
            $t->string('skip_reason')->nullable();
            $t->unsignedTinyInteger('sms_parts')->nullable();
            $t->unsignedBigInteger('outbound_message_id')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
        });
        Schema::create('outbound_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->string('channel');
            $t->string('to');
            $t->string('recipient_type')->nullable();
            $t->unsignedBigInteger('recipient_id')->nullable();
            $t->unsignedBigInteger('student_id')->nullable();
            $t->string('template_key')->nullable();
            $t->string('subject')->nullable();
            $t->text('body');
            $t->string('media_path')->nullable();
            $t->string('status')->default('queued');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('provider')->nullable();
            $t->string('provider_message_id')->nullable();
            $t->string('error')->nullable();
            $t->string('dedupe_key')->nullable();
            foreach (['scheduled_at', 'sent_at', 'delivered_at', 'read_at'] as $c) {
                $t->timestamp($c)->nullable();
            }
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('trigger')->nullable();
            $t->unsignedBigInteger('campaign_id')->nullable();
            $t->unsignedTinyInteger('sms_parts')->nullable();
            $t->boolean('is_commercial')->default(false);
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('description');
            $t->json('changes')->nullable();
            $t->string('ip_address')->nullable();
            $t->string('user_agent')->nullable();
            $t->timestamps();
        });

        DB::table('settings')->insert(['branch_id' => 1, 'group' => 'institution', 'key' => 'name', 'value' => json_encode('Erbaa Bilgi Eğitim')]);
        app(BranchContext::class)->set(1);
    }

    private function person(string $group, ?string $type, ?int $id, string $name, ?string $phone, ?string $email = null): array
    {
        $parts = explode(' ', $name);

        return ['group' => $group, 'type' => $type, 'id' => $id, 'model' => null, 'name' => $name, 'first_name' => $parts[0], 'last_name' => $parts[1] ?? '',
            'phone' => $phone, 'email' => $email, 'student_id' => null, 'vars' => ['ogrenci_ad' => 'Can Ak']];
    }

    private function consent(string $type, int $id, string $channel, string $purpose, bool $granted): void
    {
        DB::table('communication_consents')->insert(['consentable_type' => $type, 'consentable_id' => $id, 'channel' => $channel, 'purpose' => $purpose, 'granted' => $granted, 'recorded_at' => now()]);
    }

    /** @return list<array> */
    private function people(): array
    {
        return [
            $this->person('guardians', 'guardian', 1, 'Ayşe Kaya', '905321110001', 'ayse@ornek.com'),   // ticari onaylı
            $this->person('guardians', 'guardian', 2, 'Mehmet Er', '905321110002', 'mehmet@ornek.com'), // onay yok
            $this->person('guardians', 'guardian', 3, 'Fatma Su', '905321110003'),                      // bilgilendirmeyi reddetmiş
            $this->person('students', 'student', 4, 'Can Ak', '905321110001'),                          // Ayşe ile aynı numara (tekrar)
            $this->person('teachers', 'teacher', 5, 'Hoca Bey', '903562220000'),                        // sabit hat → geçersiz
            $this->person('teachers', 'teacher', 6, 'Selin Ay', null),                                  // numara yok
            $this->person('manual', null, null, 'Elle Kişi', '905321110007'),                           // elle eklenen
            $this->person('guardians', 'guardian', 8, 'Ret Eden', '905321110008'),                      // ret listesinde
        ];
    }

    private function seedConsents(): void
    {
        $this->consent('guardian', 1, 'sms', 'marketing', true);
        $this->consent('student', 4, 'sms', 'marketing', true);
        $this->consent('guardian', 8, 'sms', 'marketing', true);
        $this->consent('guardian', 3, 'sms', 'informational', false);
        CommunicationSuppression::query()->create(['branch_id' => 1, 'channel' => 'sms', 'address' => '905321110008', 'reason' => 'unsubscribe']);
    }

    public function test_commercial_sms_goes_only_to_consented_unsuppressed_unique_addresses(): void
    {
        $this->seedConsents();
        $plan = (new CampaignPlanner)->plan(1, $this->people(), [
            'channels' => ['sms'], 'is_commercial' => true, 'sms_body' => 'Sayın {ad}, yeni dönem kayıtları başladı.',
        ], ['sms' => ['ret_number' => '4609', 'unit_price' => '0,25']]);

        $s = $plan['summary']['channels']['sms'];
        $this->assertSame(1, $s['sendable']);            // yalnız Ayşe
        $this->assertSame(1, $s['duplicate']);           // Can (aynı numara, onaylı ama tekrar)
        $this->assertSame(1, $s['suppressed']);          // Ret Eden
        $this->assertSame(3, $s['no_consent']);          // Mehmet, Fatma, Elle Kişi
        $this->assertSame(1, $s['invalid']);             // sabit hat
        $this->assertSame(1, $s['no_address']);
        $this->assertSame(0.25, $s['unit_price']);

        $pending = array_values(array_filter($plan['rows'], fn ($r) => $r['status'] === 'pending'));
        $this->assertSame('Ayşe Kaya', $pending[0]['name']);
        // Ticari SMS'te ret metni eklenir, parça hesabına girer
        $this->assertStringContainsString('RET yazıp 4609', $plan['summary']['samples'][0]['sms']);
        $this->assertSame($s['parts'], $pending[0]['sms_parts']);
    }

    public function test_informational_sms_skips_only_explicit_refusals_and_suppressed(): void
    {
        $this->seedConsents();
        $plan = (new CampaignPlanner)->plan(1, $this->people(), ['channels' => ['sms', 'email'], 'is_commercial' => false,
            'sms_body' => 'Yarın kurum kapalıdır. {ogrenci_ad}', 'email_subject' => 'Duyuru {ad}', 'email_body' => 'Merhaba {ad_soyad}']);

        $sms = $plan['summary']['channels']['sms'];
        $this->assertSame(3, $sms['sendable']);  // Ayşe, Mehmet, Elle Kişi
        $this->assertSame(1, $sms['denied']);    // Fatma
        $this->assertSame(1, $sms['suppressed']);
        $this->assertNull($plan['summary']['opt_out_text']);
        $this->assertSame(2, $plan['summary']['channels']['email']['sendable']);
        $this->assertSame(6, $plan['summary']['channels']['email']['no_address']);
        $this->assertSame(3, $plan['summary']['reachable_people']);
        $this->assertSame('Duyuru Ayşe', $plan['summary']['samples'][0]['email_subject']);
        $this->assertSame([], $plan['summary']['unknown_vars']);
    }

    public function test_long_turkish_sms_part_estimate(): void
    {
        $body = str_repeat('ş', 10).str_repeat('a', 150); // 160 karakter, Türkçe → 2 parça
        $plan = (new CampaignPlanner)->plan(1, [$this->person('manual', null, null, 'X Y', '905321110009')], ['channels' => ['sms'], 'sms_body' => $body]);
        $this->assertSame(2, $plan['summary']['channels']['sms']['parts']);
        $this->assertSame('tr', $plan['summary']['channels']['sms']['encoding']);
    }

    private function simulationIntegration(array $config = []): void
    {
        $i = new Integration(['branch_id' => 1, 'kind' => 'sms', 'provider' => 'simulation', 'status' => 'connected', 'is_enabled' => true]);
        $i->setConfig($config);
        $i->save();
    }

    private function campaignWith(int $recipients, array $channels = ['sms']): MessageCampaign
    {
        $c = MessageCampaign::query()->create(['branch_id' => 1, 'name' => 'ZZTEST kampanya', 'channels' => $channels, 'audience' => ['groups' => ['manual']],
            'sms_body' => 'Merhaba {ad}', 'status' => 'sending', 'approved_by' => null]);
        for ($i = 1; $i <= $recipients; $i++) {
            MessageCampaignRecipient::query()->create(['campaign_id' => $c->id, 'branch_id' => 1, 'channel' => 'sms', 'group' => 'manual',
                'name' => "Kişi $i", 'to' => '9053211100'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'vars' => ['ad' => "Kişi$i"], 'status' => 'pending', 'sms_parts' => 1]);
        }

        return $c;
    }

    public function test_sender_respects_rate_limit_and_batches(): void
    {
        Http::fake();
        $this->simulationIntegration(['rate_per_minute' => 3]);
        $calls = new \ArrayObject;
        $this->app->bind(SimulationGateway::class, fn () => new class($calls) extends SimulationGateway
        {
            public function __construct(private \ArrayObject $calls) {}

            public function maxBatchSize(): int
            {
                return 2;
            }

            public function sendBatch(array $messages, array $config, array $options = []): array
            {
                $this->calls[] = count($messages);

                return parent::sendBatch($messages, $config, $options);
            }
        });

        $campaign = $this->campaignWith(5);
        $sender = app(CampaignSender::class);

        $r = $sender->process($campaign);
        $this->assertSame(3, $r['sent']);
        $this->assertSame(2, $r['remaining']);
        $this->assertSame([2, 1], $calls->getArrayCopy());                       // 3 alıcı, parti başına 2
        $this->assertSame('sending', $campaign->fresh()->status);

        // Aynı dakika içinde hız sınırı dolu: gönderim yok
        $this->assertSame(0, $sender->process($campaign->fresh())['sent']);

        $this->travel(61)->seconds();
        $r = $sender->process($campaign->fresh());
        $this->assertSame(2, $r['sent']);
        $this->assertSame(0, $r['remaining']);
        $this->assertSame('completed', $campaign->fresh()->status);

        $messages = OutboundMessage::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(5, $messages);
        $this->assertSame('Merhaba Kişi1', $messages->first()->body);
        $this->assertTrue($messages->every(fn ($m) => $m->status === 'sent' && $m->provider === 'simulation'));
        Http::assertNothingSent();
    }

    public function test_failed_recipients_can_be_retried_and_disconnected_channel_fails_pending(): void
    {
        Http::fake();
        $this->simulationIntegration(['fail_numbers' => '905321110002']);
        $campaign = $this->campaignWith(3);
        $sender = app(CampaignSender::class);

        $r = $sender->process($campaign);
        $this->assertSame(['sent' => 2, 'failed' => 1, 'remaining' => 0], $r);
        $this->assertSame('completed', $campaign->fresh()->status);

        // Yeniden dene: başarısız alıcı sıraya döner, aynı mesaj satırı kullanılır
        Integration::query()->first()->forceFill(['config_encrypted' => null])->save();
        $this->assertSame(1, app(CampaignService::class)->retryFailed($campaign->fresh()));
        $this->assertSame('sending', $campaign->fresh()->status);
        $this->travel(61)->seconds();
        $sender->process($campaign->fresh());
        $this->assertSame(3, OutboundMessage::query()->where('campaign_id', $campaign->id)->count());
        $this->assertSame(0, MessageCampaignRecipient::query()->where('status', 'failed')->count());
        $this->assertSame(2, (int) OutboundMessage::query()->where('to', '905321110002')->value('attempts'));

        // Kanal bağlantısı kesilirse bekleyenler açıklamalı başarısız olur
        $other = $this->campaignWith(2);
        Integration::query()->update(['status' => 'disconnected']);
        $r = $sender->process($other);
        $this->assertSame(2, $r['failed']);
        $this->assertStringContainsString('bağlı değil', MessageCampaignRecipient::query()->where('campaign_id', $other->id)->value('error'));
    }

    public function test_cancel_skips_pending_recipients(): void
    {
        $campaign = $this->campaignWith(2);
        app(CampaignService::class)->cancel($campaign);
        $this->assertSame('cancelled', $campaign->fresh()->status);
        $this->assertSame(2, MessageCampaignRecipient::query()->where('skip_reason', 'cancelled')->count());
    }

    public function test_clean_audience_whitelists_and_limits(): void
    {
        $a = CampaignService::cleanAudience(['groups' => ['students', 'hacker', 'guardians'], 'class_group_ids' => ['3', 'x', 3],
            'manual' => [['name' => ' Ali ', 'phone' => '0532 111 22 33'], 'bozuk']]);
        $this->assertSame(['students', 'guardians'], $a['groups']);
        $this->assertSame([3], $a['class_group_ids']);
        $this->assertSame([['name' => 'Ali', 'phone' => '0532 111 22 33', 'email' => null]], $a['manual']);
        $this->assertCount(2, \App\Services\Campaigns\CampaignAudience::parseManual("Ali Veli; 0532 111 22 33\nsadece isim\nveli@ornek.com, Ayşe"));
    }

    public function test_public_unsubscribe_confirms_then_records_suppression_and_consent(): void
    {
        $token = UnsubscribeToken::make(1, 'email', 'guardian', 7, 'Veli@Ornek.com');

        $this->get('/api/v1/abonelik/'.$token)->assertOk()->assertSee('Abonelikten çık')->assertSee('V•••@Ornek.com', false);
        $this->assertSame(0, CommunicationSuppression::query()->count()); // GET kaydetmez

        $this->post('/api/v1/abonelik/'.$token, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('sonlandırıldı');
        $this->assertSame('veli@ornek.com', CommunicationSuppression::query()->value('address'));
        $this->assertFalse((bool) DB::table('communication_consents')->where(['consentable_type' => 'guardian', 'consentable_id' => 7, 'channel' => 'email', 'purpose' => 'marketing'])->value('granted'));

        $this->get('/api/v1/abonelik/bozuk.'.str_repeat('a', 32))->assertNotFound();
    }

    public function test_campaign_email_is_escaped_and_has_unsubscribe_link(): void
    {
        $m = new OutboundMessage(['channel' => 'email', 'to' => 'a@b.com', 'subject' => 'Duyuru', 'body' => "Merhaba <b>Ali</b>\n\nBilgi: https://ornek.com/kayit.", 'campaign_id' => 5, 'recipient_type' => 'guardian', 'recipient_id' => 3, 'is_commercial' => true]);
        $m->branch_id = 1;
        $r = EmailRenderer::render($m);

        $this->assertStringContainsString('&lt;b&gt;Ali&lt;/b&gt;', $r['html']);
        $this->assertStringContainsString('<a href="https://ornek.com/kayit"', $r['html']);
        $this->assertStringContainsString('Erbaa Bilgi Eğitim', $r['html']);
        $this->assertNotNull($r['unsubscribe_url']);
        $this->assertStringContainsString($r['unsubscribe_url'], $r['html']);
        $this->assertStringContainsString('Ticari elektronik ileti', $r['html']);
    }

    // ------------------------------------------------------------ Yetkiler

    private function route(string $method, string $uri): RoutingRoute
    {
        foreach (Route::getRoutes()->getRoutes() as $r) {
            if ($r->uri() === 'api/v1/'.$uri && in_array($method, $r->methods(), true)) {
                return $r;
            }
        }
        $this->fail("Rota yok: $method $uri");
    }

    private function perms(RoutingRoute $r): array
    {
        return array_values(array_map(fn ($m) => substr($m, 11), array_filter($r->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))));
    }

    public function test_permissions_and_role_split(): void
    {
        $all = Permissions::all();
        foreach (['messages.campaign', 'messages.campaign_send', 'messages.consents', 'integrations.sms', 'integrations.email'] as $p) {
            $this->assertContains($p, $all);
        }
        $roles = Permissions::defaultRoles();
        $this->assertContains('messages.campaign', $roles['danisman']['permissions']);
        $this->assertNotContains('messages.campaign_send', $roles['danisman']['permissions'], 'Danışman taslak hazırlar, gönderemez');
        $this->assertContains('messages.campaign_send', $roles['mudur']['permissions']);
        $this->assertContains('messages.campaign_send', $roles['yonetici']['permissions']);
        $this->assertNotContains('messages.campaign', $roles['muhasebe']['permissions']);
        $this->assertNotContains('messages.campaign', $roles['ogretmen']['permissions']);
        $this->assertContains('integrations.sms', $roles['sistem']['permissions']);

        $this->assertSame(['messages.campaign_send'], $this->perms($this->route('POST', 'campaigns/{campaign}/approve')));
        $this->assertSame(['messages.campaign_send'], $this->perms($this->route('POST', 'campaigns/{campaign}/retry')));
        $this->assertSame(['messages.campaign'], $this->perms($this->route('POST', 'campaigns/preview')));
        $this->assertSame(['messages.campaign'], $this->perms($this->route('POST', 'campaigns')));
        $this->assertSame(['messages.consents'], $this->perms($this->route('POST', 'message-consents')));
        $this->assertSame(['integrations.manage|integrations.sms|integrations.email'], $this->perms($this->route('PUT', 'messaging-channels/{kind}')));
        $this->assertSame(['reports.view', 'messages.view'], $this->perms($this->route('GET', 'reports/communication')));
        $this->assertContains('staff', $this->route('POST', 'campaigns/{campaign}/approve')->gatherMiddleware());

        $public = $this->route('POST', 'abonelik/{token}');
        $this->assertNotContains('auth:sanctum', $public->gatherMiddleware());
        $this->assertContains(\App\Http\Middleware\VerifyCsrfUnlessBearer::class, $public->excludedMiddleware());
    }
}
