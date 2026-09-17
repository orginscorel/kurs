<?php

namespace App\Sync\Local;

class SyncHttpException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $errorCode = '')
    {
        parent::__construct($message, $status);
    }

    public function isOffline(): bool
    {
        return $this->status === 0 || $this->status >= 500 || $this->status === 429;
    }

    public function isRevoked(): bool
    {
        return $this->status === 401 || $this->errorCode === 'device_revoked';
    }
}
