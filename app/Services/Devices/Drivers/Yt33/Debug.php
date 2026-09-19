<?php

namespace App\Services\Devices\Drivers\Yt33;

use Illuminate\Support\Facades\Log;

/**
 * `terminal` kanalına tek biçimli satırlar:
 *   [YT33][TCP] Connecting 192.168.68.60:5005 · [YT33][TCP] Connected in 4ms · [YT33][TX] 8 a5 5a …
 *   [YT33][RX] … · [YT33][TIMEOUT] … · [YT33][SOCKET_CLOSE] <neden>
 * İkili veri yalnız HEX olarak yazılır (dizge kodlamasıyla bozulmaz).
 */
final class Debug
{
    public function __construct(private readonly string $tag = 'YT33') {}

    public function tcp(string $message, array $context = []): void
    {
        $this->line('TCP', $message, $context);
    }

    public function tx(string $bytes): void
    {
        $this->line('TX', strlen($bytes).' '.bin2hex($bytes));
    }

    public function rx(string $bytes): void
    {
        $this->line('RX', strlen($bytes).' '.bin2hex($bytes));
    }

    public function timeout(string $message): void
    {
        $this->line('TIMEOUT', $message);
    }

    public function close(string $reason): void
    {
        $this->line('SOCKET_CLOSE', $reason);
    }

    public function error(string $message, array $context = []): void
    {
        $this->line('ERROR', $message, $context);
    }

    private function line(string $kind, string $message, array $context = []): void
    {
        Log::channel('terminal')->info("[{$this->tag}][{$kind}] {$message}", $context);
    }
}
