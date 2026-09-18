<?php

namespace App\Services\Devices\Terminal;

/**
 * DÜĞÜME ÖZEL terminal durumu (son soket testi, köprü IP'si, protokol durumu, son ham tanılamalar).
 *
 * Bilinçli olarak veritabanında DEĞİL, yerel düğümün storage klasöründe küçük bir JSON dosyasıdır:
 * eşitlenmez (başka düğüm için anlamsız), göç gerektirmez, kaybolursa bir sonraki testte yeniden oluşur.
 * Web'e salt okunur özet, eşitleme ajanının durum raporu üzerinden çıkar (bkz. snapshot()).
 */
class TerminalStateStore
{
    private const MAX_DIAGNOSTICS = 50;

    public function path(): string
    {
        return (string) config('devices_zk.state_path', storage_path('app/terminal/state.json'));
    }

    public function device(int $deviceId): array
    {
        return $this->read()['devices'][(string) $deviceId] ?? [];
    }

    public function putDevice(int $deviceId, array $values): void
    {
        $this->mutate(function (array $state) use ($deviceId, $values) {
            $key = (string) $deviceId;
            $state['devices'][$key] = array_merge($state['devices'][$key] ?? [], $values);

            return $state;
        });
    }

    /** Ham TCP tanılama kayıtları (en yeni başta, en çok 50). */
    public function diagnostics(): array
    {
        return $this->read()['diagnostics'] ?? [];
    }

    public function pushDiagnostic(array $record): array
    {
        $record['id'] = bin2hex(random_bytes(6));

        $this->mutate(function (array $state) use ($record) {
            $list = $state['diagnostics'] ?? [];
            array_unshift($list, $record);
            $state['diagnostics'] = array_slice($list, 0, self::MAX_DIAGNOSTICS);

            return $state;
        });

        return $record;
    }

    public function findDiagnostic(string $id): ?array
    {
        foreach ($this->diagnostics() as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }

        return null;
    }

    /** Eşitleme ajanının web'e taşıyacağı salt okunur özet (sır içermez). */
    public function snapshot(int $deviceId): array
    {
        $d = $this->device($deviceId);

        return [
            'driver' => $d['surucu'] ?? null,
            'bridge_ip' => $d['kopru_ip'] ?? null,
            'tcp_status' => $d['tcp_durum'] ?? null,
            'protocol_status' => $d['protokol_durum'] ?? null,
            'last_test_at' => $d['son_test'] ?? null,
            'last_error' => $d['son_hata'] ?? null,
        ];
    }

    private function read(): array
    {
        $raw = @file_get_contents($this->path());
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : ['devices' => [], 'diagnostics' => []];
    }

    private function mutate(callable $fn): void
    {
        $dir = dirname($this->path());
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = fopen($this->path().'.lock', 'c');
        try {
            if ($handle) {
                flock($handle, LOCK_EX);
            }
            $state = $fn($this->read());
            $tmp = $this->path().'.'.getmypid().'.tmp';
            file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            rename($tmp, $this->path());
        } finally {
            if ($handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }
}
