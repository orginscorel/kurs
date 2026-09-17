<?php

namespace Tests\Unit;

use App\Services\Campaigns\ConsentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Kayıt formundaki ticari ileti onayı: varsayılan kapalı, yalnız değişen kanal yazılır, kaynak "Kayıt formu". */
class MarketingConsentFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }
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
    }

    public function test_unchecked_boxes_write_nothing_and_state_defaults_to_false(): void
    {
        $svc = app(ConsentService::class);
        $this->assertSame(0, $svc->syncFromForm('student', 5, ['sms' => false, 'email' => false, 'whatsapp' => false], 1));
        $this->assertSame(0, DB::table('communication_consents')->count());
        $this->assertSame(['sms' => false, 'email' => false, 'whatsapp' => false], $svc->marketingState('student', [5])[5]);
    }

    public function test_only_changed_channels_are_recorded_with_form_source(): void
    {
        $svc = app(ConsentService::class);
        $this->assertSame(2, $svc->syncFromForm('guardian', 9, ['sms' => true, 'email' => false, 'whatsapp' => true], 3));
        $row = DB::table('communication_consents')->where('channel', 'sms')->first();
        $this->assertSame('Kayıt formu', $row->source);
        $this->assertSame('marketing', $row->purpose);
        $this->assertSame(3, (int) $row->recorded_by);

        // Aynı değerlerle tekrar kaydetmek delilin tarihini değiştirmez
        DB::table('communication_consents')->update(['recorded_at' => '2026-01-01 10:00:00']);
        $this->assertSame(0, $svc->syncFromForm('guardian', 9, ['sms' => true, 'email' => false, 'whatsapp' => true], 3));
        $this->assertSame('2026-01-01 10:00:00', DB::table('communication_consents')->where('channel', 'sms')->value('recorded_at'));

        // Onay geri alınınca "hayır" kaydı yazılır; eksik anahtar dokunulmaz
        $this->assertSame(1, $svc->syncFromForm('guardian', 9, ['whatsapp' => false], 3));
        $state = $svc->marketingState('guardian', [9])[9];
        $this->assertSame(['sms' => true, 'email' => false, 'whatsapp' => false], $state);
        $this->assertSame(2, DB::table('communication_consents')->count());
    }
}
