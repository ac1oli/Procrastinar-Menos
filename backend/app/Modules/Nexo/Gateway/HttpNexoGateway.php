<?php

namespace App\Modules\Nexo\Gateway;

use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Data\Embeddings;
use App\Modules\Nexo\Data\NexoReply;
use App\Modules\Nexo\Data\ReplyContext;
use App\Modules\Nexo\Exceptions\AiFailure;
use App\Modules\Nexo\Exceptions\AiUnavailableException;
use App\Modules\Nexo\Support\ReasoningSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Implementação do NexoGateway sobre a API HTTP do ai-service.
 *
 * Nunca registra em log o conteúdo das mensagens; apenas o motivo da falha.
 */
final class HttpNexoGateway implements NexoGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly float $connectTimeout,
        private readonly float $replyTimeout,
        private readonly float $embedTimeout,
        private readonly float $healthTimeout,
        private readonly int $embeddingDimensions,
    ) {}

    public function reply(string $message, ReplyContext $context): NexoReply
    {
        $data = $this->post('/v1/nexo/reply', [
            'message' => $message,
            'context' => $context->toArray(),
        ], $this->replyTimeout);

        $text = is_string($data['reply'] ?? null) ? ReasoningSanitizer::strip($data['reply']) : '';
        if ($text === '' || ! is_string($data['model'] ?? null) || ! is_string($data['prompt_version'] ?? null)) {
            throw $this->fail(AiFailure::InvalidResponse, 'resposta de reply fora do contrato');
        }

        return new NexoReply($text, $data['model'], $data['prompt_version']);
    }

    public function embed(array $texts): Embeddings
    {
        $data = $this->post('/v1/embed', ['texts' => array_values($texts)], $this->embedTimeout);

        $vectors = $data['vectors'] ?? null;
        $valid = is_array($vectors)
            && count($vectors) === count($texts)
            && ($data['dimensions'] ?? null) === $this->embeddingDimensions
            && is_string($data['model'] ?? null);

        foreach ($valid ? $vectors : [] as $vector) {
            if (! is_array($vector) || count($vector) !== $this->embeddingDimensions
                || array_filter($vector, fn ($x) => ! is_int($x) && ! is_float($x)) !== []) {
                $valid = false;
                break;
            }
        }

        if (! $valid) {
            throw $this->fail(AiFailure::InvalidResponse, 'embeddings fora do contrato');
        }

        return new Embeddings(
            array_map(fn (array $v) => array_map('floatval', $v), $vectors),
            $data['model'],
            $this->embeddingDimensions,
        );
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->healthTimeout)
                ->acceptJson()
                ->get('/health');

            return $response->ok() && $response->json('provider') === 'up';
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $payload, float $timeout): array
    {
        if (blank($this->token)) {
            throw $this->fail(AiFailure::Misconfigured, 'NEXO_AI_SERVICE_TOKEN não configurado');
        }

        try {
            $response = $this->client($timeout)->post($path, $payload);
        } catch (ConnectionException $e) {
            throw $this->fail($this->connectionFailure($e->getMessage()), 'falha de conexão com o ai-service', $e);
        }

        if (! $response->successful()) {
            throw $this->fail($this->failureFor($response), "ai-service respondeu HTTP {$response->status()}");
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw $this->fail(AiFailure::InvalidResponse, 'resposta do ai-service não é JSON');
        }

        return $data;
    }

    /**
     * cURL 28 é "tempo esgotado", mas só é lentidão da IA se a conexão já
     * existia. Esgotar o tempo para conectar ou resolver o nome significa
     * serviço fora do ar (ex.: container parado).
     */
    private function connectionFailure(string $message): AiFailure
    {
        $timedOut = str_contains($message, 'cURL error 28');
        $notConnected = preg_match('/(Connection|Resolving) timed out/i', $message) === 1;

        return $timedOut && ! $notConnected ? AiFailure::Timeout : AiFailure::Unavailable;
    }

    private function failureFor(Response $response): AiFailure
    {
        return match (true) {
            $response->status() === 503 && $response->json('error') === 'ai_busy' => AiFailure::Busy,
            $response->status() === 504 => AiFailure::Timeout,
            $response->status() === 502 => AiFailure::InvalidResponse,
            // 401: token divergente; 422: o backend violou o contrato. Ambos são bugs nossos.
            in_array($response->status(), [401, 403, 404, 413, 422], true) => AiFailure::Misconfigured,
            default => AiFailure::Unavailable,
        };
    }

    private function client(float $timeout): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->token)
            ->connectTimeout($this->connectTimeout)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson();
    }

    private function fail(AiFailure $reason, string $detail, ?Throwable $previous = null): AiUnavailableException
    {
        $level = $reason === AiFailure::Misconfigured ? 'error' : 'warning';
        Log::log($level, 'nexo.ai_failure', ['reason' => $reason->value, 'detail' => $detail]);

        return new AiUnavailableException($reason, $detail, $previous);
    }
}
