<?php

namespace App\Modules\Nexo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nexo\Actions\SendNexoMessage;
use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Exceptions\AiUnavailableException;
use App\Modules\Nexo\Http\Requests\NexoMessageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Conversa com o NEXO. O que é guardado e usado depende dos consentimentos
 * (ADR-0008); a regra fica em SendNexoMessage.
 */
class NexoController extends Controller
{
    public function __construct(private readonly NexoGateway $gateway) {}

    public function message(NexoMessageRequest $request, SendNexoMessage $send): JsonResponse
    {
        try {
            $result = $send(
                $request->user(),
                $request->validated('message'),
                $request->validated('conversation_id'),
                $request->boolean('private'),
                $request->clientHistory(),
            );
        } catch (AiUnavailableException $e) {
            return response()->json([
                'message' => 'O NEXO está indisponível no momento. Suas rotinas, objetivos e regras de foco continuam funcionando normalmente.',
                'reason' => $e->reason->value,
            ], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => (string) config('nexo.retry_after')]);
        }

        return response()->json(['data' => $result->toArray()]);
    }

    /** Disponibilidade da IA para o dashboard; cache curto para não sobrecarregar o ai-service. */
    public function status(): JsonResponse
    {
        $available = Cache::remember('nexo.available', 15, fn () => $this->gateway->isAvailable());

        return response()->json(['data' => ['available' => $available]]);
    }
}
