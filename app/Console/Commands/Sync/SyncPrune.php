<?php

namespace App\Console\Commands\Sync;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sunucu: saklama süresinden eski değişiklik günlüğü ve alındılarını siler. Daha eski imleçle gelen
 * cihaz yeniden anlık görüntü alır (pull yanıtında resnapshot).
 */
class SyncPrune extends Command
{
    protected $signature = 'kurs:sync-prune {--days= : saklama (gün)}';

    protected $description = 'Eski eşitleme günlüğünü budar';

    public function handle(): int
    {
        if (config('kurs.node') === 'local') {
            // Yerel: gönderilmiş değişiklikler 30 gün tutulur (süpürücü "zaten yazıldı" denetimi için)
            $n = DB::table('sync_changes')->where('status', 'pushed')->where('created_at', '<', now()->subDays(30))->delete();
            $this->info("Yerel: $n gönderilmiş değişiklik silindi.");

            return self::SUCCESS;
        }
        $days = (int) ($this->option('days') ?: config('sync.retention_days', 90));
        $before = now()->subDays(max(7, $days));
        $maxId = (int) DB::table('sync_changes')->where('created_at', '<', $before)->max('id');
        if ($maxId <= 0) {
            $this->info('Budanacak kayıt yok.');

            return self::SUCCESS;
        }
        // Satır özetleri bu kayıtları işaret edebilir: "yeni değişiklik var mı" denetimi id > change_id olduğundan güvenli
        $deleted = 0;
        do {
            $n = DB::table('sync_changes')->where('id', '<=', $maxId)->orderBy('id')->limit(2000)->delete();
            $deleted += $n;
        } while ($n > 0);
        DB::table('sync_receipts')->where('created_at', '<', $before)->delete();
        DB::table('sync_tombstones')->where('deleted_at', '<', $before)->delete();
        DB::table('sync_state')->updateOrInsert(['key' => 'pruned_before'], ['value' => (string) $maxId, 'updated_at' => now()]);
        $this->info("$deleted günlük kaydı budandı (id ≤ $maxId).");

        return self::SUCCESS;
    }
}
