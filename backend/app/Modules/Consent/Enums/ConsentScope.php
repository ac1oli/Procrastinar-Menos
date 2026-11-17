<?php

namespace App\Modules\Consent\Enums;

/**
 * Finalidades do NEXO que exigem autorização própria (ADR-0008 §2.2).
 *
 * Todas começam desligadas e são verificadas no backend a cada requisição.
 */
enum ConsentScope: string
{
    case History = 'nexo.history';
    case Memory = 'nexo.memory';
    case MemorySuggestions = 'nexo.memory.suggestions';
    case MemorySensitive = 'nexo.memory.sensitive';
    case ContextRoutine = 'nexo.context.routine';
    case ContextGoals = 'nexo.context.goals';
    case ContextActivity = 'nexo.context.activity';

    /**
     * Escopos que precisam estar concedidos antes deste.
     *
     * Sugestões dependem do histórico porque são geradas a partir de mensagens
     * já gravadas: assim nenhum conteúdo de conversa trafega pela fila sem
     * autorização de armazenamento.
     *
     * @return list<self>
     */
    public function requires(): array
    {
        return match ($this) {
            self::MemorySuggestions => [self::Memory, self::History],
            self::MemorySensitive => [self::Memory],
            default => [],
        };
    }

    /** @return list<self> Escopos que caem junto quando este é revogado. */
    public function dependents(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $scope) => in_array($this, $scope->requires(), true),
        ));
    }

    /** Revogar exige escolher o destino dos dados guardados (D7). */
    public function holdsData(): bool
    {
        return in_array($this, [self::History, self::Memory, self::MemorySensitive], true);
    }

    /** Atividade dos agentes chega na FASE 5; até lá não pode ser concedida. */
    public function isAvailable(): bool
    {
        return $this !== self::ContextActivity;
    }
}
