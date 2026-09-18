<?php

namespace App\Sync\Local;

class SyncHttpException extends \RuntimeException
{
    /** @param string $detail Teknik ayrıntı (ör. "cURL error 6: Could not resolve host"); kullanıcı metninden ayrı tutulur */
    public function __construct(string $message, public readonly int $status = 0, public readonly string $errorCode = '', public readonly string $detail = '')
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
