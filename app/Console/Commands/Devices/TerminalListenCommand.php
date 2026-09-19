<?php

namespace App\Console\Commands\Devices;

use App\Services\Devices\Terminal\PushListener;
use App\Services\Devices\Terminal\PushListenerException;
use App\Services\Devices\Terminal\TerminalStateStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * PUSH DİNLEYİCİSİ SÜRECİ — masaüstü uygulaması (runtime.rs › terminal_listener) bunu denetlenen görev olarak
 * başlatır, çökerse yeniden başlatır, kapanışta SIGTERM ile düzgün kapatır.
 *
 * Çıkış kodları (Rust bunlara göre bekler):
 *   0 → push kapalı / düzgün durdu (Rust 20 sn sonra ayarı yeniden dener)
 *   2 → port açılamadı (kullanımda / izin yok) — hata durum dosyasına Türkçe yazılır
 *   3 → ayar değişti (port) — Rust hemen yeniden başlatır
 *   1 → beklenmeyen hata
 * Ayar 5 sn'de bir yeniden okunur: port ya da aktarma hedefi değişince süreç kendini yeniden kurar.
 */
class TerminalListenCommand extends Command
{
    protected $signature = 'kurs:terminal-dinle
        {--port= : Ayardaki port yerine bu port (test)}
        {--sure= : En çok bu kadar saniye çalış (test)}
        {--zorla : Ayar kapalı olsa da dinle (test)}';

    protected $description = 'Yoklama terminallerinin push verisini (varsayılan TCP 7005) dinler, ham saklar, isteğe bağlı aktarır';

    private bool $stop = false;

    public function handle(PushListener $listener, TerminalStateStore $state): int
    {
        if (config('kurs.node') !== 'local') {
            $this->error('Push dinleyicisi yalnız masaüstü yerel düğümünde çalışır (sunucu yerel ağa bağlanmaz).');

            return self::FAILURE;
        }

        $settings = $state->pushSettings();

        if (! $settings['acik'] && ! $this->option('zorla')) {
            $state->putListenerState(['durum' => 'kapali', 'pid' => null, 'hata' => null, 'guncellendi' => now()->toIso8601String()]);
            $this->line('Push dinleyicisi kapalı (Terminal Köprüsü › Push ayarından açılır).');

            return self::SUCCESS;
        }

        $port = (int) ($this->option('port') ?: $settings['port']);

        try {
            $listener->open($port);
        } catch (PushListenerException $e) {
            $state->putListenerState(['durum' => 'hata', 'port' => $port, 'pid' => null, 'hata' => $e->getMessage(), 'oneri' => $e->hint, 'guncellendi' => now()->toIso8601String()]);
            Log::channel('terminal')->error('Push dinleyicisi açılamadı', ['port' => $port, 'hata' => $e->getMessage(), 'errno' => $e->errno]);
            $this->error($e->getMessage().' '.$e->hint);

            return 2;
        }

        $listener->setUpstream($settings['aktar_ip'], $settings['aktar_port']);
        $this->trapSignals();

        $started = now()->toIso8601String();
        $state->putListenerState([
            'durum' => 'aktif', 'port' => $listener->port(), 'pid' => getmypid(), 'basladi' => $started,
            'aktarma' => $listener->upstreamLabel(), 'hata' => null, 'oneri' => null, 'kalp' => $started,
            'baglanti_sayisi' => 0, 'paket_sayisi' => 0, 'son_ip' => null, 'son_zaman' => null, 'aktarma_hatasi' => null,
        ], true);
        Log::channel('terminal')->info('Push dinleyicisi başladı', ['port' => $listener->port(), 'aktarma' => $listener->upstreamLabel()]);
        $this->info("Dinleniyor: 0.0.0.0:{$listener->port()}".($listener->upstreamLabel() ? ' · aktarma → '.$listener->upstreamLabel() : ''));

        $until = $this->option('sure') ? microtime(true) + (float) $this->option('sure') : null;
        $lastBeat = 0.0;
        $lastPackets = -1;
        $restart = false;

        try {
            while (! $this->stop && ($until === null || microtime(true) < $until)) {
                $listener->tick(0.25);

                $now = microtime(true);
                if ($now - $lastBeat >= 5.0 || $listener->packets !== $lastPackets) {
                    $state->putListenerState([
                        'kalp' => now()->toIso8601String(), 'baglanti_sayisi' => $listener->connections, 'paket_sayisi' => $listener->packets,
                        'acik_baglanti' => $listener->activeConnections(), 'son_ip' => $listener->lastIp, 'son_zaman' => $listener->lastAt,
                        'aktarma_hatasi' => $listener->lastRelayError,
                    ]);
                    $lastPackets = $listener->packets;

                    if ($now - $lastBeat >= 5.0) {
                        $lastBeat = $now;
                        $fresh = $state->pushSettings();
                        if (! $fresh['acik'] && ! $this->option('zorla')) {
                            break;
                        }
                        if (! $this->option('port') && $fresh['port'] !== $listener->port()) {
                            $restart = true;
                            break;   // port değişti → 3 ile çık; Rust hemen yeniden başlatır
                        }
                        $listener->setUpstream($fresh['aktar_ip'], $fresh['aktar_port']);
                    }
                }
            }
        } finally {
            $listener->close();
            $state->putListenerState(['durum' => 'kapali', 'pid' => null, 'kapandi' => now()->toIso8601String(), 'paket_sayisi' => $listener->packets]);
            Log::channel('terminal')->info('Push dinleyicisi durdu', ['paket' => $listener->packets]);
        }

        return $restart ? 3 : self::SUCCESS;
    }

    private function trapSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
            pcntl_signal($signal, function () {
                $this->stop = true;
            });
        }
    }
}
