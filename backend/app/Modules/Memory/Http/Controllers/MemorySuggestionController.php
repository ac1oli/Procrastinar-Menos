<?php

namespace App\Modules\Memory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Memory\Enums\MemoryKind;
use App\Modules\Memory\Enums\MemoryOrigin;
use App\Modules\Memory\Http\Resources\MemoryResource;
use App\Modules\Memory\MemoryGuard;
use App\Modules\Memory\MemoryService;
use App\Modules\Memory\Models\MemorySuggestion;
use App\Modules\Nexo\Privacy\SensitiveTopicDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Caixa de sugestões: nada inferido vira memória sem confirmação (R4, D10).
 * Recusar apaga a sugestão sem deixar registro.
 */
class MemorySuggestionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $suggestions = MemorySuggestion::ownedBy($request->user())->pending()->latest('created_at')->get();

        return response()->json(['data' => $suggestions->map(fn (MemorySuggestion $s) => [
            'id' => $s->id,
            'kind' => $s->kind->value,
            'content' => $s->content,
            'rationale' => $s->rationale,
            'source' => ['conversation_id' => $s->source_conversation_id, 'message_id' => $s->source_message_id],
            'created_at' => $s->created_at->toIso8601String(),
            'expires_at' => $s->expires_at->toIso8601String(),
        ])]);
    }

    /** Aceita como está ou com edição. Conteúdo sensível só por criação manual (D4). */
    public function accept(
        Request $request,
        MemorySuggestion $suggestion,
        MemoryService $memories,
        MemoryGuard $guard,
        SensitiveTopicDetector $topics,
        AuditLogger $audit,
    ): JsonResponse {
        Gate::authorize('update', $suggestion);
        abort_if($suggestion->expires_at->isPast(), Response::HTTP_NOT_FOUND);

        $data = $request->validate([
            'content' => ['sometimes', 'string', 'max:'.config('nexo.memory.max_content_chars')],
            'kind' => ['sometimes', Rule::enum(MemoryKind::class)],
        ]);
        $content = $data['content'] ?? $suggestion->content;

        $guard->rejectIdentifiers($content);
        if ($topics->isSensitive($content)) {
            throw ValidationException::withMessages([
                'content' => 'Dados sensíveis não entram por sugestão. Se quiser guardar, crie a memória manualmente.',
            ]);
        }

        $memory = DB::transaction(function () use ($request, $suggestion, $memories, $data, $content) {
            $memory = $memories->create(
                $request->user(),
                ['kind' => $data['kind'] ?? $suggestion->kind->value, 'content' => $content],
                MemoryOrigin::SuggestionConfirmed,
                ['conversation_id' => $suggestion->source_conversation_id, 'message_id' => $suggestion->source_message_id],
            );
            $suggestion->delete();

            return $memory;
        });

        $audit->record('nexo.suggestion.accepted', $request->user(), $memory, ['edited' => isset($data['content'])]);

        return MemoryResource::make($memory->refresh()->load('embedding'))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function reject(MemorySuggestion $suggestion): Response
    {
        Gate::authorize('delete', $suggestion);

        $suggestion->delete();

        return response()->noContent();
    }
}
