<?php

namespace App\Modules\Memory\Retrieval;

use App\Modules\Memory\Models\Memory;

final readonly class RetrievedMemory
{
    public function __construct(
        public Memory $memory,
        /** Pontuação final (RRF × pesos); null para memórias fixadas. */
        public ?float $score,
        /** Cosseno com a pergunta, quando houve busca vetorial. */
        public ?float $similarity,
    ) {}

    /** @return array{kind: string, content: string} formato enviado à IA (sem id) */
    public function forModel(): array
    {
        return ['kind' => $this->memory->kind->value, 'content' => $this->memory->content];
    }
}
