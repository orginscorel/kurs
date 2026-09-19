<?php

namespace App\Services\Devices\Terminal;

use App\Services\Devices\Network\RawTcpDiagnostic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Push dinleyicisinin aldığı ham paketlerin deposu (terminal_raw_packets, LOCAL).
 * Kural: hiçbir paket silinmez ya da "düzeltilmez"; ayrıştırılamayan = "Unknown raw device event".
 */
class TerminalPacketStore
{
    public const TABLE = 'terminal_raw_packets';

    /**
     * Tek bir "parça": bir yönde art arda gelen baytlar (boşluk/kapanışla biter).
     *
     * @param  array{method:string, path:string, headers:string, body:string}|null  $http
     */
    public function store(
        string $payload, string $direction, string $connectionId, string $remoteIp, ?int $remotePort, ?int $localPort,
        bool $truncated, ?array $http, string $closeReason, ?string $upstream = null, ?string $upstreamStatus = null,
        ?string $note = null,
    ): int {
        $sha = hash('sha256', $payload);
        $duplicateOf = DB::table(self::TABLE)->where('sha256', $sha)->whereNull('duplicate_of')->orderBy('id')->value('id');
        $deviceId = DB::table('devices')->where('zk_ip', $remoteIp)->whereNull('deleted_at')->orderBy('id')->value('id');
        $now = now();

        $id = (int) DB::table(self::TABLE)->insertGetId([
            'source' => 'push',
            'direction' => $direction,
            'connection_id' => $connectionId,
            'upstream' => $upstream,
            'upstream_status' => $upstreamStatus,
            'device_id' => $deviceId,
            'remote_ip' => $remoteIp,
            'remote_port' => $remotePort,
            'local_port' => $localPort,
            'received_at' => $now,
            'byte_count' => strlen($payload),
            'truncated' => $truncated,
            'sha256' => $sha,
            'duplicate_of' => $duplicateOf,
            'format' => $http ? 'http' : ($payload === '' ? 'empty' : (preg_match('/^[\x09\x0A\x0D\x20-\x7E]*$/', $payload) ? 'text' : 'binary')),
            'http_method' => $http['method'] ?? null,
            'http_path' => isset($http['path']) ? mb_substr($http['path'], 0, 500) : null,
            'http_headers' => $http['headers'] ?? null,
            'http_body_base64' => isset($http['body']) ? base64_encode($http['body']) : null,
            'payload_base64' => base64_encode($payload),
            'close_reason' => $closeReason,
            'parse_status' => 'unparsed',
            'note' => $note ?? 'Unknown raw device event — bu cihaz için doğrulanmış ayrıştırıcı yok; ham paket saklandı.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Log::channel('terminal')->info('Push paketi', ['id' => $id, 'yon' => $direction, 'kaynak' => "{$remoteIp}:{$remotePort}", 'bayt' => strlen($payload), 'http' => $http !== null, 'aktarma' => $upstream, 'tekrar' => $duplicateOf !== null]);

        return $id;
    }

    /** Son N paket (liste için; gövde yok). */
    public function latest(int $limit = 50): array
    {
        return DB::table(self::TABLE)->orderByDesc('id')->limit($limit)
            ->get(['id', 'direction', 'connection_id', 'upstream', 'upstream_status', 'device_id', 'remote_ip', 'remote_port', 'received_at', 'byte_count', 'truncated', 'sha256', 'duplicate_of', 'format', 'http_method', 'http_path', 'parse_status'])
            ->map(fn ($r) => (array) $r + ['tekrar' => $r->duplicate_of !== null])->all();
    }

    public function count(): int
    {
        return (int) DB::table(self::TABLE)->count();
    }

    /** Tek paket ayrıntısı: HEX dökümü, ASCII önizleme, HTTP parçaları. */
    public function detail(int $id): ?array
    {
        $row = DB::table(self::TABLE)->find($id);

        if (! $row) {
            return null;
        }

        $payload = (string) base64_decode($row->payload_base64);
        $body = $row->http_body_base64 !== null ? (string) base64_decode($row->http_body_base64) : null;

        return [
            'id' => $row->id,
            'yon' => $row->direction,
            'baglanti' => $row->connection_id,
            'aktarma' => $row->upstream,
            'aktarma_durumu' => $row->upstream_status,
            'cihaz_id' => $row->device_id,
            'kaynak_ip' => $row->remote_ip,
            'kaynak_port' => $row->remote_port,
            'yerel_port' => $row->local_port,
            'zaman' => $row->received_at,
            'bayt' => (int) $row->byte_count,
            'kesildi' => (bool) $row->truncated,
            'sha256' => $row->sha256,
            'tekrar_of' => $row->duplicate_of,
            'bicim' => $row->format,
            'kapanis' => $row->close_reason,
            'ayristirma' => $row->parse_status,
            'not' => $row->note,
            'http' => $row->format === 'http' ? [
                'metot' => $row->http_method,
                'yol' => $row->http_path,
                'basliklar' => $row->http_headers,
                'govde_bayt' => strlen((string) $body),
                'govde_ascii' => RawTcpDiagnostic::ascii((string) $body),
                'govde_hex' => RawTcpDiagnostic::hexDump((string) $body),
            ] : null,
            'hex' => RawTcpDiagnostic::hexDump($payload),
            'ascii' => RawTcpDiagnostic::ascii($payload),
        ];
    }

    public function text(array $d): string
    {
        $lines = [
            'Erbaa Kurs — Push ham paketi #'.$d['id'],
            'Zaman        : '.$d['zaman'],
            'Kaynak       : '.$d['kaynak_ip'].':'.$d['kaynak_port'].' → yerel port '.$d['yerel_port'],
            'Yön          : '.self::directionLabel($d['yon'], $d['aktarma']),
            'Aktarma      : '.($d['aktarma'] ? $d['aktarma'].' ('.($d['aktarma_durumu'] ?? '?').')' : 'yok (yalnız dinleme)'),
            'Bağlantı     : '.$d['baglanti'],
            'Bayt         : '.$d['bayt'].($d['kesildi'] ? ' (1 MB sınırında kesildi)' : ''),
            'SHA-256      : '.$d['sha256'].($d['tekrar_of'] ? ' (tekrar — ilk kayıt #'.$d['tekrar_of'].')' : ''),
            'Biçim        : '.$d['bicim'],
            'Kapanış      : '.$d['kapanis'],
            'Not          : '.$d['not'],
        ];

        if ($d['http']) {
            $lines[] = '';
            $lines[] = '--- HTTP';
            $lines[] = $d['http']['metot'].' '.$d['http']['yol'];
            $lines[] = (string) $d['http']['basliklar'];
            $lines[] = '--- HTTP gövdesi ('.$d['http']['govde_bayt'].' bayt, ASCII)';
            $lines[] = $d['http']['govde_ascii'];
        }

        $lines[] = '';
        $lines[] = '--- Ham baytlar (HEX)';
        $lines[] = $d['hex'] !== '' ? $d['hex'] : '(boş)';

        return implode("\n", $lines)."\n";
    }

    public static function directionLabel(string $direction, ?string $upstream): string
    {
        return match ($direction) {
            'device_to_upstream' => 'Cihaz → Köprü → Sunucu ('.$upstream.')',
            'upstream_to_device' => 'Sunucu ('.$upstream.') → Köprü → Cihaz',
            default => 'Cihaz → Köprü',
        };
    }
}
