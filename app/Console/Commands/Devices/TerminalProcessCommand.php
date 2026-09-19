<?php

namespace App\Console\Commands\Devices;

use App\Services\Devices\Drivers\Yt33\Push\RealtimeIngest;
use Illuminate\Console\Command;

/**
 * Saklanmış push isteklerini yeniden işler: cihaz sonradan tanımlandıysa ya da paketler 1.15.1 dinleyicisiyle
 * (ayrıştırıcı yokken) geldiyse okutmalar yoklamaya/PDKS'ye, kullanıcı kayıtları PDKS kişilerine işlenir.
 * Dinleyici her açılışta bunu kendisi de çalıştırır.
 */
class TerminalProcessCommand extends Command
{
    protected $signature = 'kurs:terminal-isle
        {--limit=5000 : En çok bu kadar paket}
        {--hatalilar : Daha önce hata veren paketleri de yeniden dene}';

    protected $description = 'Terminal push paketlerinden işlenmemiş okutma ve kullanıcı kayıtlarını işler';

    public function handle(RealtimeIngest $ingest): int
    {
        $s = $ingest->reprocess((int) $this->option('limit'), (bool) $this->option('hatalilar'));

        $this->info("Taranan {$s['taranan']} · işlenen {$s['islenen']} · cihazı tanımsız {$s['cihazsiz']} · hatalı {$s['hatali']}");

        return self::SUCCESS;
    }
}
