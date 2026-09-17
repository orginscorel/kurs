<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * İş kuralı ihlali: mesaj doğrudan kullanıcıya gösterilir.
 * Örn. "Bu derslik 10:00-11:00 arasında 12-SAY-A sınıfına ayrılmış."
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'business_rule',
        public readonly array $context = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
