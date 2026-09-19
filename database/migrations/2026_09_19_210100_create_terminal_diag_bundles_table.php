<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Uzaktan tanı paketi — masaüstü köprüden gelen terminal teşhis verisi (log, state, push paketleri, web paneli
| keşfi) sunucuda saklanır ki geliştirici uzaktan inceleyebilsin. SUNUCUYA ÖZGÜ tablo (eşitlenmez; SyncRegistry
| 'sync_' önekiyle SYSTEM sayılmasın diye açıkça LOCAL değil — bu tablo yalnız sunucuda var, masaüstünde kullanılmaz).
| Blob storage/app/private/terminal-diag altında; kayıtta yalnız yol + özet. Yalnız ekleyen migration.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_diag_bundles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('sync_device_id')->nullable()->index();
            $table->string('device_label', 120)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->json('summary')->nullable();
            $table->string('blob_path', 300)->nullable();
            $table->unsignedInteger('blob_size')->default(0);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_diag_bundles');
    }
};
