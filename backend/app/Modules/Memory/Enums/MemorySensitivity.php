<?php

namespace App\Modules\Memory\Enums;

/** "sensitive" só existe por criação manual com nexo.memory.sensitive (D4). */
enum MemorySensitivity: string
{
    case Normal = 'normal';
    case Sensitive = 'sensitive';
}
