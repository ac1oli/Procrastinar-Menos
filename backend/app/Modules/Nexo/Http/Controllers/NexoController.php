<?php

namespace App\Modules\Nexo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Data\ReplyContext;
use App\Modules\Nexo\Exceptions\AiUnavailableException;
use App\Modules\Nexo\Http\Requests\NexoMessageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Conversa direta com o NEXO (FASE 3: sem memória persistida).
 *
 * As mensagens ainda não são armazenadas; a memória do NEXO será um módulo
 * próprio, com consentimento e retenção configuráveis.
 */
class NexoController extends Controller
{
    public function __construct(private readonly NexoGateway $gateway) {}

    public function message(NexoMessageRequest $request): JsonResponse
    {
        try {
            $reply = $this->gateway->reply(
                $request->validated('message'),
                ReplyContext::forUser($request->user()),
            );
        } catch (AiUnavailableException $e) {
            return response()->json([
                'message' => 'O NEXO está indisponível no momento. Suas rotinas, objetivos e regras de foco continuam funcionando normalmente.',
                'reason' => $e->reason->value,
            ], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => (string) config('nexo.retry_after')]);
        }

        return response()->json([
            'data' => [
                'reply' => $reply->text,
                'model' => $reply->model,
                'prompt_version' => $reply->promptVersion,
            ],
        ]);
    }

    /** Disponibilidade da IA para o dashboard; cache curto para não sobrecarregar o ai-service. */
    public function status(): JsonResponse
    {
        $available = Cache::remember('nexo.available', 15, fn () => $this->gateway->isAvailable());

        return response()->json(['data' => ['available' => $available]]);
    }
}
