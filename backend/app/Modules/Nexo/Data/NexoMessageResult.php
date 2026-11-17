<?php

namespace App\Modules\Nexo\Data;

use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationMessage;

final readonly class NexoMessageResult
{
    /**
     * @param  list<array{id: string, kind: string, content: string}>  $memoriesUsed
     */
    public function __construct(
        public NexoReply $reply,
        public bool $private,
        public ?Conversation $conversation = null,
        public ?ConversationMessage $userMessage = null,
        public ?ConversationMessage $assistantMessage = null,
        public array $memoriesUsed = [],
    ) {}

    public function toArray(): array
    {
        return [
            'reply' => $this->reply->text,
            'model' => $this->reply->model,
            'prompt_version' => $this->reply->promptVersion,
            'private' => $this->private,
            'stored' => $this->conversation !== null,
            'conversation_id' => $this->conversation?->id,
            // Quantos dados foram mascarados na mensagem antes de guardar (D5).
            'masked_in_history' => $this->userMessage?->redaction_count,
            'memories_used' => $this->memoriesUsed,
        ];
    }
}
