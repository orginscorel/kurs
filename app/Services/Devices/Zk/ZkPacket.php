<?php

namespace App\Services\Devices\Zk;

/** Cihazdan gelen tek bir çerçeve: komut başlığı + veri. */
final class ZkPacket
{
    public function __construct(
        public readonly int $command,
        public readonly int $checksum,
        public readonly int $sessionId,
        public readonly int $replyId,
        public readonly string $data,
    ) {}

    /** 8 baytlık komut başlığı + kalan veri. Gelen sağlama DOĞRULANMAZ (bkz. ZkProtocol::checksum). */
    public static function parse(string $frame): self
    {
        $head = unpack('vcommand/vchecksum/vsession/vreply', substr($frame, 0, 8));

        return new self($head['command'], $head['checksum'], $head['session'], $head['reply'], substr($frame, 8));
    }

    public function isSuccess(): bool
    {
        return ZkProtocol::isSuccess($this->command);
    }

    public function commandName(): string
    {
        return match ($this->command) {
            ZkProtocol::CMD_ACK_OK => 'ACK_OK',
            ZkProtocol::CMD_ACK_ERROR => 'ACK_ERROR',
            ZkProtocol::CMD_ACK_DATA => 'ACK_DATA',
            ZkProtocol::CMD_ACK_RETRY => 'ACK_RETRY',
            ZkProtocol::CMD_ACK_REPEAT => 'ACK_REPEAT',
            ZkProtocol::CMD_ACK_UNAUTH => 'ACK_UNAUTH',
            ZkProtocol::CMD_ACK_UNKNOWN => 'ACK_UNKNOWN',
            ZkProtocol::CMD_PREPARE_DATA => 'PREPARE_DATA',
            ZkProtocol::CMD_DATA => 'DATA',
            default => (string) $this->command,
        };
    }
}
