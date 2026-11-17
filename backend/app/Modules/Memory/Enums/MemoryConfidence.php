<?php

namespace App\Modules\Memory\Enums;

/** Confiança por regra determinística, nunca pela autoavaliação do modelo. */
enum MemoryConfidence: string
{
    case High = 'alta';
    case Medium = 'media';
    case Low = 'baixa';

    /** Peso na recuperação (ADR-0008 / proposta de arquitetura §5.1). */
    public function weight(): float
    {
        return match ($this) {
            self::High => 1.0,
            self::Medium => 0.85,
            self::Low => 0.7,
        };
    }
}
