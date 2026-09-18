<?php

namespace App\Services\Devices\Discovery;

/**
 * EŞZAMANLI PORT SÜPÜRME — bloklamayan soket + stream_select.
 *
 * 254 adresi tek tek denemek (her biri 300 ms) 76 saniye sürerdi. Burada bütün bağlantılar
 * AYNI ANDA açılır (STREAM_CLIENT_ASYNC_CONNECT) ve stream_select ile hangisinin tamamlandığı
 * beklenir; /24 süpürme tipik olarak yarım saniyede biter.
 *
 * Bağlantı tamamlanınca soket "yazılabilir" olur. Başarılı mı reddedildi mi ayrımı
 * stream_socket_get_name($s, true) ile yapılır: bağlanamayan sokette karşı taraf adı yoktur.
 *
 * Hiçbir çağrı asılı kalmaz: genel süre sınırı (deadline) her turda kontrol edilir.
 */
final class PortSweep
{
    /**
     * @param  list<string>  $hosts
     * @param  float  $timeout  adres başına saniye (0.3 = 300 ms)
     * @param  int  $concurrency  aynı anda açık soket sayısı (dosya tanıtıcısı sınırı için)
     * @return array{acik: list<string>, denenen: int, sure_ms: int, kesildi: bool}
     */
    public function scan(array $hosts, int $port = 4370, float $timeout = 0.3, int $concurrency = 128, float $budget = 15.0): array
    {
        $started = microtime(true);
        $deadline = $started + max(1.0, $budget);
        $timeout = min(max(0.05, $timeout), 3.0);

        $open = [];
        $tried = 0;
        $aborted = false;

        foreach (array_chunk(array_values($hosts), max(8, $concurrency)) as $batch) {
            if (microtime(true) >= $deadline) {
                $aborted = true;
                break;
            }

            $tried += count($batch);
            foreach ($this->sweepBatch($batch, $port, $timeout, $deadline) as $host) {
                $open[] = $host;
            }
        }

        sort($open, SORT_NATURAL);

        return [
            'acik' => $open,
            'denenen' => $tried,
            'sure_ms' => (int) round((microtime(true) - $started) * 1000),
            'kesildi' => $aborted,
        ];
    }

    /**
     * Tek parti: hepsini aç, tamamlananları topla.
     *
     * @param  list<string>  $hosts
     * @return list<string>
     */
    private function sweepBatch(array $hosts, int $port, float $timeout, float $deadline): array
    {
        /** @var array<string, resource> $pending */
        $pending = [];
        $open = [];

        foreach ($hosts as $host) {
            $errNo = 0;
            $errStr = '';
            $stream = @stream_socket_client(
                "tcp://{$host}:{$port}", $errNo, $errStr, 0,
                STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT,
            );

            if ($stream === false) {
                continue;   // yerel hata (dosya tanıtıcısı bitti vb.) — bu adres atlanır
            }

            stream_set_blocking($stream, false);
            $pending[$host] = $stream;
        }

        $end = min(microtime(true) + $timeout, $deadline);

        while ($pending !== [] && ($remaining = $end - microtime(true)) > 0) {
            $read = null;
            $write = array_values($pending);
            $except = array_values($pending);

            $ready = @stream_select($read, $write, $except, 0, (int) min(250_000, max(1000, $remaining * 1_000_000)));

            if ($ready === false) {
                break;   // sinyal kesintisi vb.: kalanlar zaman aşımına düşer
            }

            if ($ready === 0) {
                continue;
            }

            foreach ($write as $stream) {
                $host = array_search($stream, $pending, true);
                if ($host === false) {
                    continue;
                }

                // Bağlantı tamamlandı: karşı taraf adı okunabiliyorsa AÇIK, okunamıyorsa reddedildi.
                if (@stream_socket_get_name($stream, true) !== false) {
                    $open[] = (string) $host;
                }

                @fclose($stream);
                unset($pending[$host]);
            }

            foreach ($except as $stream) {
                $host = array_search($stream, $pending, true);
                if ($host !== false) {
                    @fclose($stream);
                    unset($pending[$host]);
                }
            }
        }

        foreach ($pending as $stream) {
            @fclose($stream);   // süre doldu: kapalı/sessiz adres
        }

        return $open;
    }
}
