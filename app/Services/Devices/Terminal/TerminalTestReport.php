<?php

namespace App\Services\Devices\Terminal;

use App\Services\Devices\Drivers\TerminalDriver;

final class TerminalTestReport
{
    /** @param  array<string, TestStage>  $stages */
    public function __construct(
        public readonly string $status,          // ok | kismi | hata
        public readonly ?string $code,           // baglanti | protokol | protokol_dogrulanmadi | desteklenmiyor | kimlik
        public readonly string $message,
        public readonly string $hint,
        public readonly TerminalDriver $driver,
        public readonly TerminalEndpoint $endpoint,
        public readonly array $stages,
        public readonly ?array $socket,
        public readonly ?array $identity,
    ) {}

    public function toArray(): array
    {
        return [
            'durum' => $this->status,
            'kod' => $this->code,
            'mesaj' => $this->message,
            'oneri' => $this->hint,
            'hedef' => $this->endpoint->label(),
            'surucu' => ['anahtar' => $this->driver->key(), 'etiket' => $this->driver->label()],
            'kopru_ip' => $this->socket['yerel_ip'] ?? null,
            'asamalar' => array_map(fn (TestStage $s) => $s->toArray(), $this->stages),
            'soket' => $this->socket,
            'cihaz' => $this->identity,
        ];
    }
}
