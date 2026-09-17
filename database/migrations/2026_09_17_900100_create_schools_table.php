<?php

use App\Support\SchoolCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kurumun öğrenci aldığı okullar (bölge kataloğundan ya da elle). Ek tablo; mevcut veriye dokunmaz.
 * Kurum Tokat/Erbaa'da olduğundan mevcut şubelere Erbaa + Taşova liseleri başlangıç listesi olarak eklenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('schools')) {
            Schema::create('schools', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
                $t->string('name', 160);
                $t->string('kind', 20)->default('diger');
                $t->string('city', 60)->nullable();
                $t->string('district', 60)->nullable();
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
                $t->unique(['branch_id', 'name']);
                $t->index(['branch_id', 'is_active']);
            });
        }

        $now = now();
        foreach (DB::table('branches')->pluck('id') as $branchId) {
            if (DB::table('schools')->where('branch_id', $branchId)->exists()) {
                continue;
            }
            $i = 0;
            foreach (['tokat-erbaa', 'amasya-tasova'] as $key) {
                $r = SchoolCatalog::REGIONS[$key];
                foreach ($r['schools'] as [$name, $kind]) {
                    DB::table('schools')->insert(['branch_id' => $branchId, 'name' => $name, 'kind' => $kind, 'city' => $r['city'], 'district' => $r['district'],
                        'is_active' => true, 'sort_order' => ++$i, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
