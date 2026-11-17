<?php

namespace App\Modules\Conversations;

use App\Models\User;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationMessage;
use App\Modules\Conversations\Models\NexoPreference;
use App\Modules\Nexo\Data\NexoReply;
use App\Modules\Nexo\Data\Turn;
use App\Modules\Nexo\Privacy\SensitiveDataRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grava o histórico. Só é chamado depois que o NexoController confirmou o
 * consentimento nexo.history e que a conversa não é privada.
 *
 * Tudo passa pelo SensitiveDataRedactor antes de chegar ao banco (D5).
 */
final class ConversationRecorder
{
    public function __construct(private readonly SensitiveDataRedactor $redactor) {}

    /**
     * Grava a troca (pergunta + resposta) numa transação. Se a IA falhar antes,
     * nada é gravado: a mensagem continua no cliente para nova tentativa.
     *
     * @return array{conversation: Conversation, user: ConversationMessage, assistant: ConversationMessage}
     */
    public function recordExchange(User $user, ?Conversation $conversation, string $message, NexoReply $reply): array
    {
        $question = $this->redactor->redact($message);
        $answer = $this->redactor->redact($reply->text);

        return DB::transaction(function () use ($user, $conversation, $question, $answer, $reply) {
            $now = CarbonImmutable::now();

            $conversation ??= Conversation::create([
                'user_id' => $user->getKey(),
                'title' => Str::limit(trim(preg_replace('/\s+/', ' ', $question->text)), 80),
                'last_message_at' => $now,
            ]);

            $userMessage = $conversation->messages()->create([
                'user_id' => $user->getKey(),
                'role' => 'user',
                'content' => $question->text,
                'redaction_count' => $question->count,
                'created_at' => $now,
            ]);

            $assistantMessage = $conversation->messages()->create([
                'user_id' => $user->getKey(),
                'role' => 'assistant',
                'content' => $answer->text,
                'redaction_count' => $answer->count,
                'model' => $reply->model,
                'prompt_version' => $reply->promptVersion,
                // Um microssegundo depois, para a ordem ser estável.
                'created_at' => $now->addMicrosecond(),
            ]);

            $conversation->update([
                'last_message_at' => $now,
                'expires_at' => self::expiresAt($now, NexoPreference::historyRetentionDays($user)),
            ]);

            return ['conversation' => $conversation, 'user' => $userMessage, 'assistant' => $assistantMessage];
        });
    }

    /**
     * Últimos turnos guardados, do mais antigo ao mais novo, dentro do orçamento.
     *
     * @return list<Turn>
     */
    public function recentTurns(Conversation $conversation): array
    {
        $messages = $conversation->messages()
            ->latest('created_at')
            ->limit((int) config('nexo.history.recent_turns'))
            ->get(['role', 'content']);

        return self::withinBudget($messages->map(fn ($m) => new Turn($m->role, $m->content))->reverse()->values()->all());
    }

    /**
     * Mantém os turnos mais recentes que cabem no orçamento de caracteres.
     *
     * @param  list<Turn>  $turns
     * @return list<Turn>
     */
    public static function withinBudget(array $turns): array
    {
        $budget = (int) config('nexo.history.recent_turns_max_chars');
        $kept = [];

        foreach (array_reverse(array_slice($turns, -(int) config('nexo.history.recent_turns'))) as $turn) {
            $budget -= mb_strlen($turn->content);
            if ($budget < 0) {
                break;
            }
            array_unshift($kept, $turn);
        }

        return $kept;
    }

    public static function expiresAt(CarbonImmutable $lastMessageAt, ?int $retentionDays): ?CarbonImmutable
    {
        return $retentionDays === null ? null : $lastMessageAt->addDays($retentionDays);
    }

    /** Recalcula o vencimento de todas as conversas após mudar a retenção. */
    public function applyRetention(User $user, ?int $retentionDays): int
    {
        $expiresAt = $retentionDays === null
            ? 'NULL'
            : "last_message_at + interval '".((int) $retentionDays)." days'";

        return Conversation::ownedBy($user)->update(['expires_at' => DB::raw($expiresAt)]);
    }
}
