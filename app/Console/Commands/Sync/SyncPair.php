<?php

namespace App\Console\Commands\Sync;

use App\Sync\Local\LocalSyncEngine;
use Illuminate\Console\Command;

/** Yerel düğümü sunucuyla eşleştirir (kurum kodu + personel hesabı) ve isterse anlık görüntü alır. */
class SyncPair extends Command
{
    protected $signature = 'kurs:sync-pair
        {server : sunucu adresi (https://kurs.ornek.com)}
        {code : kurum kodu (Ayarlar › Bağlı cihazlar)}
        {login : personel kullanıcı adı}
        {--password= : parola (verilmezse sorulur)}
        {--name= : cihaz adı}
        {--platform=windows : windows|macos|linux}
        {--snapshot : eşleştirmeden sonra anlık görüntü al}';

    protected $description = 'Yerel kurulumu web sunucusuyla eşleştirir';

    public function handle(LocalSyncEngine $engine): int
    {
        if (config('kurs.node') !== 'local') {
            $this->error('Bu komut yalnız yerel düğümde çalışır (KURS_NODE=local).');

            return self::FAILURE;
        }
        $password = $this->option('password') ?: $this->secret('Parola');
        $res = $engine->pair(
            (string) $this->argument('server'), (string) $this->argument('code'), (string) $this->argument('login'),
            (string) $password, (string) ($this->option('name') ?: gethostname()), (string) $this->option('platform'),
        );
        $this->info(sprintf('Eşleştirildi: %s (%s). Kurum veri anahtarı: %s', $res['device']['name'], $res['device']['code'], $res['key']));

        if ($this->option('snapshot')) {
            $snap = $engine->snapshot();
            $this->info(sprintf('Anlık görüntü: %d tablo, %d satır, imleç %d, ertelenen %d (%d ms).', $snap['tables'], $snap['rows'], $snap['cursor'], $snap['deferred'], $snap['ms']));
        }

        return self::SUCCESS;
    }
}
