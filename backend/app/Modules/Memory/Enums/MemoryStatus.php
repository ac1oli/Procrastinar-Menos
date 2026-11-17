<?php

namespace App\Modules\Memory\Enums;

enum MemoryStatus: string
{
    case Active = 'active';
    /** Sem uso nem revisão há muito tempo (D3): continua usável, com peso menor. */
    case NeedsReview = 'needs_review';
    /** Guardada, visível e exportável, mas nunca usada pelo NEXO. */
    case Archived = 'archived';
}
