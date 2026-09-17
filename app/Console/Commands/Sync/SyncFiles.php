<?php

namespace App\Console\Commands\Sync;

use App\Sync\Files\FileIndex;
use App\Sync\Local\LocalFileSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dosya eşitlemesi (docs/SYNC.md › Dosyalar).
 *  sunucu: --index → başvurulan dosyaların sha256 dizinini tazeler (zamanlayıcıda 10 dk'da bir; manifest de tazeler)
 *  yerel : tek dosya turu (kurs:sync zaten her turda çalıştırır); --retry başarısızları hemen yeniden dener
 *  her iki düğüm: durum özeti
 */
class SyncFiles extends Command
{
    protected $signature = 'kurs:sync-files {--index : dizini tazele} {--retry : yerel: bekleyen/başarısız dosyaları hemen dene} {--json}';

    protected $description = 'Eşitlenen dosyaların (fotoğraf, belge, ödev, disiplin eki) dizini ve kuyruğu';

    public function handle(FileIndex $index): int
    {
        $local = config('kurs.node') === 'local';
        $out = [];
        if ($this->option('index')) {
            $out['index'] = $index->refresh(null, $local);
            $out['index']['new'] = count($out['index']['new']);
        }
        if ($local && ($this->option('retry') || ! $this->option('index'))) {
            if ($this->option('retry')) {
                DB::table('sync_files')->whereIn('status', ['download', 'upload', 'failed'])->update(['next_attempt_at' => null]);
            }
            $run = app(LocalFileSync::class)->run();
            $run['scan']['new'] = count($run['scan']['new']);
            $out['run'] = $run;
        }
        $out['status'] = DB::table('sync_files')->selectRaw('status, COUNT(*) AS n, COALESCE(SUM(size), 0) AS bytes')->groupBy('status')->get()
            ->mapWithKeys(fn ($r) => [$r->status => ['files' => (int) $r->n, 'mb' => round($r->bytes / 1048576, 2)]])->all();
        $out['failed'] = DB::table('sync_files')->where('status', 'failed')->limit(10)->get(['path', 'attempts', 'last_error'])->map(fn ($r) => (array) $r)->all();
        $this->line(json_encode($out, JSON_UNESCAPED_UNICODE | ($this->option('json') ? JSON_PRETTY_PRINT : 0)));

        return self::SUCCESS;
    }
}
