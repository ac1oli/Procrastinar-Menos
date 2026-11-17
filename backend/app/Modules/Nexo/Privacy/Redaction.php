<?php

namespace App\Modules\Nexo\Privacy;

final readonly class Redaction
{
    public function __construct(
        public string $text,
        public int $count,
    ) {}
}
