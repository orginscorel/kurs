<?php

namespace App\Console\Commands\Desktop;

use App\Sync\Local\LocalSyncEngine;
use App\Sync\Local\SyncHttpException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Masaüstü kurulum sihirbazının eşleştirme adımı (makine arayüzü; insanlar için kurs:sync-pair).
 *
 * Girdi STDIN'den JSON: {server, code, login, password, name, platform}. Parola komut satırında görünmesin diye
 * argüman olarak alınmaz. Çıktı satır satır JSON:
 *   {"event":"paired","device":{…},"key":"alındı (data_key)"}
 *   {"event":"secrets","device_token":"…","data_key":"…"|null}
 *   {"event":"error","message":"…","code":"…"}
 *
 * Sırlar (cihaz jetonu, kurum veri anahtarı) çıktı verildikten sonra yerel veritabanından SİLİNİR; masaüstü
 * uygulaması onları işletim sistemi anahtar zincirinde saklar ve her açılışta SYNC_DEVICE_TOKEN / KURS_DATA_KEY
 * ortam değişkeni olarak verir.
 */
class DesktopPair extends Command
{
    protected $signature = 'kurs:desktop-pair {--keep-secrets : sırları yerel veritabanında da bırak (yalnız hata ayıklama)}';

    protected $description = 'Masaüstü uygulaması: STDIN JSON ile eşleştirir, sırları JSON olarak verir (makine arayüzü)';

    protected $hidden = true;

    public function handle(LocalSyncEngine $engine): int
    {
        if (config('kurs.node') !== 'local') {
            return $this->emit(['event' => 'error', 'code' => 'not_local', 'message' => 'Bu komut yalnız yerel düğümde çalışır (KURS_NODE=local).'], self::FAILURE);
        }

        $raw = stream_get_contents(STDIN, 64 * 1024);
        $in = is_string($raw) ? json_decode($raw, true) : null;
        foreach (['server', 'code', 'login', 'password'] as $k) {
            if (! is_array($in) || ! is_string($in[$k] ?? null) || trim($in[$k]) === '') {
                return $this->emit(['event' => 'error', 'code' => 'bad_input', 'message' => 'Eşleştirme bilgileri eksik ('.$k.').'], self::INVALID);
            }
        }
        $server = rtrim(trim($in['server']), '/');
        if (! preg_match('#^https://[^/\s]+$#i', $server) && ! preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#i', $server)) {
            return $this->emit(['event' => 'error', 'code' => 'bad_server', 'message' => 'Sunucu adresi https:// ile başlamalı.'], self::INVALID);
        }
        $platform = in_array($in['platform'] ?? '', ['windows', 'macos', 'linux'], true) ? $in['platform'] : 'macos';

        try {
            $res = $engine->pair($server, trim($in['code']), trim($in['login']), (string) $in['password'],
                mb_substr(trim((string) ($in['name'] ?? '')) ?: (string) gethostname(), 0, 120), $platform);
        } catch (ValidationException $e) {
            return $this->emit(['event' => 'error', 'code' => 'invalid', 'message' => collect($e->errors())->flatten()->first() ?: $e->getMessage()], self::FAILURE);
        } catch (SyncHttpException $e) {
            return $this->emit(['event' => 'error', 'code' => $e->isOffline() ? 'offline' : 'server', 'message' => $e->getMessage()], self::FAILURE);
        } catch (\Throwable $e) {
            report($e);

            return $this->emit(['event' => 'error', 'code' => 'unexpected', 'message' => 'Eşleştirme tamamlanamadı: '.$e->getMessage()], self::FAILURE);
        }

        $this->emit(['event' => 'paired', 'device' => $res['device'] ?? null, 'key' => $res['key'] ?? null]);

        $read = function (string $key): ?string {
            $enc = DB::table('sync_state')->where('key', $key)->value('value');
            if (! $enc) {
                return null;
            }
            try {
                return Crypt::decryptString((string) $enc);
            } catch (\Throwable) {
                return null;
            }
        };
        $token = $read('device_token');
        $dataKey = $read('data_key');
        if (! $token) {
            return $this->emit(['event' => 'error', 'code' => 'no_token', 'message' => 'Sunucu cihaz jetonu vermedi.'], self::FAILURE);
        }

        $this->emit(['event' => 'secrets', 'device_token' => $token, 'data_key' => $dataKey]);

        if (! $this->option('keep-secrets')) {
            // device_token_set=1 kalır (gösterge "eşleşmiş" der); jeton ortam değişkeninden gelir
            DB::table('sync_state')->whereIn('key', ['device_token', 'data_key'])->delete();
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $data */
    private function emit(array $data, ?int $code = null): int
    {
        $this->output->writeln(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);

        return $code ?? self::SUCCESS;
    }
}
