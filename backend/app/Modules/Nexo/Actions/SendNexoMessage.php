<?php

namespace App\Modules\Nexo\Actions;

use App\Models\User;
use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Conversations\ConversationRecorder;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Data\NexoMessageResult;
use App\Modules\Nexo\Data\ReplyContext;
use App\Modules\Nexo\Data\Turn;
use App\Modules\Nexo\Exceptions\AiUnavailableException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Uma mensagem ao NEXO, do consentimento à gravação (ADR-0008 §2).
 *
 * O contexto temporário é montado aqui e descartado ao fim. Só grava se
 * nexo.history estiver concedido e a conversa não for privada; e só grava
 * depois da resposta, para que uma falha da IA não deixe meia conversa.
 */
final class SendNexoMessage
{
    public function __construct(
        private readonly NexoGateway $gateway,
        private readonly ConsentService $consents,
        private readonly ConversationRecorder $recorder,
    ) {}

    /**
     * @param  list<Turn>  $clientHistory  turnos mantidos pelo navegador (sem histórico ou modo privado)
     *
     * @throws AiUnavailableException
     */
    public function __invoke(
        User $user,
        string $message,
        ?string $conversationId,
        bool $private,
        array $clientHistory,
    ): NexoMessageResult {
        $persist = ! $private && $this->consents->allows($user, ConsentScope::History);

        if ($conversationId !== null && ! $persist) {
            throw new ConflictHttpException('O histórico está desligado: esta mensagem não pode ser adicionada a uma conversa guardada.');
        }

        $conversation = $conversationId !== null
            ? Conversation::ownedBy($user)->findOrFail($conversationId)
            : null;

        $history = match (true) {
            $conversation !== null => $this->recorder->recentTurns($conversation),
            $persist => [],
            default => ConversationRecorder::withinBudget($clientHistory),
        };

        $reply = $this->gateway->reply($message, ReplyContext::forUser($user), $history);

        $stored = $persist ? $this->recorder->recordExchange($user, $conversation, $message, $reply) : null;

        return new NexoMessageResult(
            reply: $reply,
            private: $private,
            conversation: $stored['conversation'] ?? null,
            userMessage: $stored['user'] ?? null,
            assistantMessage: $stored['assistant'] ?? null,
        );
    }
}
