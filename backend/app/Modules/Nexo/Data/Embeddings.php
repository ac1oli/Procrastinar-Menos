<?php

namespace App\Modules\Nexo\Data;

final readonly class Embeddings
{
    /**
     * @param  list<list<float>>  $vectors
     */
    public function __construct(
        public array $vectors,
        public string $model,
        public int $dimensions,
    ) {}
}
