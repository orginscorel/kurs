<?php

namespace Tests\Unit;

use App\Models\Contract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Sözleşme değişmezlik güvencesi (bellek içi SQLite; canlı veritabanına ASLA dokunmaz).
 *  - İmzadan ÖNCE metin (body_template / body_snapshot) serbestçe değiştirilebilir.
 *  - İmzadan SONRA body_snapshot değiştirilemez (Contract model updating guard).
 */
class ContractImmutabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->fail('Bu test yalnız bellek içi SQLite ile çalışmalı.');
        }
        Schema::create('contracts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('enrollment_id');
            $t->string('contract_no', 30)->unique();
            $t->longText('body_snapshot');
            $t->longText('body_template')->nullable();
            $t->string('pdf_path')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->string('signed_by_name', 160)->nullable();
            $t->timestamps();
        });
    }

    public function test_body_and_template_editable_before_signing(): void
    {
        $c = Contract::query()->create(['enrollment_id' => 1, 'contract_no' => 'SZL-1', 'body_snapshot' => '<p>ilk</p>']);

        $c->forceFill(['body_template' => '{net_bedel} özel metin', 'body_snapshot' => '<p>ikinci</p>'])->save();

        $this->assertSame('<p>ikinci</p>', $c->fresh()->body_snapshot);
        $this->assertSame('{net_bedel} özel metin', $c->fresh()->body_template);
    }

    public function test_signed_body_snapshot_cannot_change(): void
    {
        $c = Contract::query()->create(['enrollment_id' => 1, 'contract_no' => 'SZL-2', 'body_snapshot' => '<p>imzalı metin</p>']);
        $c->forceFill(['signed_at' => now(), 'signed_by_name' => 'Veli Adı'])->save();

        $this->expectException(\LogicException::class);
        $c->forceFill(['body_snapshot' => '<p>değiştirilmiş</p>'])->save();
    }

    public function test_signed_metadata_change_without_body_is_allowed(): void
    {
        $c = Contract::query()->create(['enrollment_id' => 1, 'contract_no' => 'SZL-3', 'body_snapshot' => '<p>metin</p>']);
        $c->forceFill(['signed_at' => now(), 'signed_by_name' => 'İlk'])->save();

        // body_snapshot dokunulmadan pdf_path güncellemesi engellenmez.
        $c->forceFill(['pdf_path' => 'sozlesme/szl-3.pdf'])->save();
        $this->assertSame('sozlesme/szl-3.pdf', $c->fresh()->pdf_path);
    }
}
