<?php

namespace App\Modules\Memory\Enums;

/** Níveis de evidência do projeto (ARCHITECTURE.md §3). */
enum EvidenceLevel: string
{
    case Observed = 'observada';
    case Declared = 'declarada';
    case Consulted = 'consultada';
    case Practiced = 'praticada';
    case Demonstrated = 'demonstrada';
}
