<?php

namespace App\Modules\Nexo\Data;

final readonly class NexoReply
{
    public function __construct(
        public string $text,
        public string $model,
        public string $promptVersion,
    ) {}
}
