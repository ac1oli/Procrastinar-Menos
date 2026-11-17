<?php

namespace App\Modules\Memory\Enums;

enum MemoryKind: string
{
    case Preference = 'preferencia';
    case PersonalGoal = 'objetivo_pessoal';
    case Knowledge = 'conhecimento';
    case Pattern = 'padrao';
    case LifeContext = 'contexto_de_vida';
    case Constraint = 'restricao';
}
