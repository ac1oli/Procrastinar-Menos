<?php

namespace App\Modules\Memory\Jobs;

use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Conversations\Models\ConversationMessage;
use App\Modules\Memory\Enums\MemoryKind;
use App\Modules\Memory\MemoryGuard;
use App\Modules\Memory\Models\Memory;
use App\Modules\Memory\Models\MemorySuggestion;
use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Data\Turn;
use App\Modules\Nexo\Exceptions\AiUnavailableException;
use App\Modules\Shared\Database\DatabaseUserScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pede ao NEXO sugestões de memória para uma troca já guardada (D10).
 *
 * Só identificadores na fila: o texto é lido do histórico (já mascarado).
 * Melhor esforço: se a IA falhar, não há sugestão e nada é repetido.
 * Toda proposta passa pelo MemoryGuard; qualquer sinal sensível descarta.
 */
class SuggestMemories implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $userId,
        public readonly string $assistantMessageId,
    ) {}

    public function handle(NexoGateway $gateway, ConsentService $consents, MemoryGuard $guard): void
    {
        DatabaseUserScope::run($this->userId, function () use ($gateway, $consents, $guard) {
            $answer = ConversationMessage::find($this->assistantMessageId);
            if ($answer === null || ! $consents->allows($answer->user, ConsentScope::MemorySuggestions)) {
                return;
            }

            $question = ConversationMessage::where('conversation_id', $answer->conversation_id)
                ->where('role', 'user')
                ->where('created_at', '<=', $answer->created_at)
                ->latest('created_at')
                ->first();

            $pending = MemorySuggestion::ownedBy($answer->user)->pending()->count();
            $room = min((int) config('nexo.memory.suggestions_per_exchange'), (int) config('nexo.memory.max_pending_suggestions') - $pending);
            if ($question === null || $room <= 0) {
                return;
            }

            try {
                $proposals = $gateway->suggestMemories(new Turn('user', $question->content), new Turn('assistant', $answer->content), $room);
            } catch (AiUnavailableException) {
                return;
            }

            foreach ($proposals as $proposal) {
                $content = trim(preg_replace('/\s+/u', ' ', $proposal->content));
                $kind = MemoryKind::tryFrom($proposal->kind);

                if ($kind === null || $content === '' || mb_strlen($content) > (int) config('nexo.memory.max_content_chars')
                    || ! $guard->acceptsSuggestion($content, $proposal->sensitivity)
                    || $this->isDuplicate($answer, $content)) {
                    continue;
                }

                MemorySuggestion::create([
                    'user_id' => $answer->user_id,
                    'kind' => $kind,
                    'content' => $content,
                    'rationale' => mb_substr(trim($proposal->rationale), 0, 300),
                    'source_conversation_id' => $answer->conversation_id,
                    'source_message_id' => $question->id,
                    'expires_at' => now()->addDays((int) config('nexo.memory.suggestion_ttl_days')),
                    'created_at' => now(),
                ]);
            }
        });
    }

    private function isDuplicate(ConversationMessage $answer, string $content): bool
    {
        $hash = Memory::hashContent($content);

        return Memory::ownedBy($answer->user)->where('content_hash', $hash)->exists()
            || MemorySuggestion::ownedBy($answer->user)->pending()->get(['content'])
                ->contains(fn (MemorySuggestion $s) => Memory::hashContent($s->content) === $hash);
    }
}
