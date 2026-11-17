<?php

namespace Tests\Fakes;

use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Data\Embeddings;
use App\Modules\Nexo\Data\NexoReply;
use App\Modules\Nexo\Data\ReplyContext;
use App\Modules\Nexo\Exceptions\AiFailure;
use App\Modules\Nexo\Exceptions\AiUnavailableException;

/** Gateway em memória para testes de feature (simula IA disponível ou fora do ar). */
final class FakeNexoGateway implements NexoGateway
{
    /** @var list<array{message: string, context: ReplyContext}> */
    public array $replies = [];

    public function __construct(
        public ?AiFailure $failure = null,
        public string $replyText = 'Resposta de teste do NEXO.',
    ) {}

    public function reply(string $message, ReplyContext $context): NexoReply
    {
        $this->replies[] = ['message' => $message, 'context' => $context];

        if ($this->failure) {
            throw new AiUnavailableException($this->failure);
        }

        return new NexoReply($this->replyText, 'fake-model', 'nexo-system@test');
    }

    public function embed(array $texts): Embeddings
    {
        if ($this->failure) {
            throw new AiUnavailableException($this->failure);
        }

        return new Embeddings(array_map(fn () => array_fill(0, 1024, 0.0), $texts), 'fake-embed', 1024);
    }

    public function isAvailable(): bool
    {
        return $this->failure === null;
    }
}
