<?php

namespace App\Modules\Nexo\Exceptions;

use RuntimeException;
use Throwable;

final class AiUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly AiFailure $reason,
        string $detail = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($detail ?: $reason->value, 0, $previous);
    }
}
