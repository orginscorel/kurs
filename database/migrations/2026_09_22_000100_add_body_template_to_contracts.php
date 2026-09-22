<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sözleşme editörü: kayda özel, imzadan ÖNCE düzenlenebilir sözleşme kaynağı (yer tutuculu şablon metni).
 * body_snapshot render edilmiş (dondurulmuş) HTML kalır; body_template ise düzenlenebilir kaynaktır.
 * SQLite-güvenli: yalnız yeni nullable kolon eklenir (mevcut kolona dokunulmaz, ->change() yok).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contracts', 'body_template')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->longText('body_template')->nullable()->after('body_snapshot');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contracts', 'body_template')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->dropColumn('body_template');
            });
        }
    }
};
