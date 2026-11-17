<?php

namespace App\Modules\Nexo\Data;

/** Proposta do modelo. Ainda não validada pelo backend nem confirmada pela pessoa. */
final readonly class SuggestedMemory
{
    public function __construct(
        public string $kind,
        public string $content,
        public string $rationale,
        public string $sensitivity,
    ) {}
}
