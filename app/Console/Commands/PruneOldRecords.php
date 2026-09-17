<?php

namespace App\Console\Commands;

use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * KVKK saklama politikası: ayarlardaki süreleri aşan operasyonel kayıtları siler.
 * Denetim kayıtları (audit_logs) ve muhasebe kayıtları bu komutla SİLİNMEZ.
 * Ayrılan öğrencilerin anonimleştirilmesi ayrı komuttadır: kurs:anonymize-withdrawn.
 */
class PruneOldRecords extends Command
{
    protected $signature = 'kurs:prune {--dry-run : Silmeden yalnız sayıları göster}';

    protected $description = 'Saklama süresi dolan akış, giriş denemesi, ham cihaz olayı ve giden mesajları temizler';

    /** Sonuçlanmış mesaj durumları (kuyruktaki/gönderilmekte olan mesaja dokunulmaz). */
    public const FINAL_MESSAGE_STATUSES = ['sent', 'delivered', 'read', 'failed', 'cancelled', 'skipped'];

    public function handle(): int
    {
        $retention = Settings::group('retention');
        $days = fn (string $key, int $fallback) => max(30, (int) ($retention[$key] ?? $fallback));

        $queries = [
            'activity_feed' => DB::table('activity_feed')->where('occurred_at', '<', now()->subDays(120)),
            'login_events' => DB::table('login_events')->where('created_at', '<', now()->subDays($days('login_events_days', 365))),
            'attendance_events' => DB::table('attendance_events')->where('occurred_at', '<', now()->subDays($days('attendance_events_days', 730))),
            'app_notifications' => DB::table('app_notifications')->whereNotNull('read_at')->where('created_at', '<', now()->subDays(90)),
            'outbound_messages' => DB::table('outbound_messages')->whereIn('status', self::FINAL_MESSAGE_STATUSES)
                ->where('created_at', '<', now()->subDays($days('outbound_messages_days', 730))),
        ];

        $result = [];
        foreach ($queries as $table => $query) {
            if ($this->option('dry-run')) {
                $result[$table] = $query->count();

                continue;
            }
            // Büyük tablolarda uzun kilit tutmamak için parça parça sil.
            $total = 0;
            do {
                $ids = (clone $query)->orderBy('id')->limit(2000)->pluck('id');
                $n = $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
                $total += $n;
            } while ($n > 0);
            $result[$table] = $total;
        }
        $this->line(($this->option('dry-run') ? 'Silinecek: ' : 'Silindi: ').json_encode($result));

        return self::SUCCESS;
    }
}
