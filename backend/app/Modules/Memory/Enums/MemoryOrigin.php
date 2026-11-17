<?php

namespace App\Modules\Memory\Enums;

/** Como a memória entrou. Não existe origem "automática" (ADR-0008 §2.3). */
enum MemoryOrigin: string
{
    case UserCreated = 'user_created';
    case SuggestionConfirmed = 'suggestion_confirmed';
    case Imported = 'imported';
    case AgentObserved = 'agent_observed';
}
