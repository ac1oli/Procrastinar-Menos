<?php

namespace Tests\Fakes;

use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Data\EmbeddingPurpose;
use App\Modules\Nexo\Data\Embeddings;
use App\Modules\Nexo\Data\NexoReply;
use App\Modules\Nexo\Data\ReplyContext;
use App\Modules\Nexo\Data\SuggestedMemory;
use App\Modules\Nexo\Data\Turn;
use App\Modules\Nexo\Exceptions\AiFailure;
use App\Modules\Nexo\Exceptions\AiUnavailableException;

/**
 * Gateway em memória para testes de feature (simula IA disponível ou fora do ar).
 *
 * Os embeddings são "saco de palavras" determinístico: textos com palavras em
 * comum ficam próximos no cosseno, o que permite testar a busca semântica sem
 * modelo real.
 */
final class FakeNexoGateway implements NexoGateway
{
    /** @var list<array{message: string, context: ReplyContext, history: list<Turn>}> */
    public array $replies = [];

    /** @var list<array{texts: list<string>, purpose: EmbeddingPurpose}> */
    public array $embeddings = [];

    /** @var list<array{user: Turn, assistant: Turn}> */
    public array $suggestCalls = [];

    /** @param list<SuggestedMemory> $suggestions */
    public function __construct(
        public ?AiFailure $failure = null,
        public string $replyText = 'Resposta de teste do NEXO.',
        public array $suggestions = [],
        public ?AiFailure $embedFailure = null,
    ) {}

    public function reply(string $message, ReplyContext $context, array $history = []): NexoReply
    {
        $this->replies[] = ['message' => $message, 'context' => $context, 'history' => $history];

        if ($this->failure) {
            throw new AiUnavailableException($this->failure);
        }

        return new NexoReply($this->replyText, 'fake-model', 'nexo-system@test');
    }

    public function embed(array $texts, EmbeddingPurpose $purpose = EmbeddingPurpose::Document): Embeddings
    {
        $this->embeddings[] = ['texts' => $texts, 'purpose' => $purpose];

        if ($failure = $this->embedFailure ?? $this->failure) {
            throw new AiUnavailableException($failure);
        }

        return new Embeddings(array_map(self::vector(...), $texts), (string) config('nexo.embedding_model'), 1024);
    }

    public function suggestMemories(Turn $user, Turn $assistant, int $max): array
    {
        $this->suggestCalls[] = ['user' => $user, 'assistant' => $assistant];

        if ($this->failure) {
            throw new AiUnavailableException($this->failure);
        }

        return array_slice($this->suggestions, 0, $max);
    }

    public function isAvailable(): bool
    {
        return $this->failure === null;
    }

    /** @return list<float> */
    public static function vector(string $text): array
    {
        $vector = array_fill(0, 1024, 0.0);
        $vector[0] = 0.01; // nunca o vetor nulo (cosseno indefinido)

        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $words);
        foreach ($words[0] as $word) {
            $vector[1 + crc32($word) % 1023] += 1.0;
        }

        return $vector;
    }
}
