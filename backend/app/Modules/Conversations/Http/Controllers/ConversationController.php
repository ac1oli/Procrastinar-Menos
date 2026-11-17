<?php

namespace App\Modules\Conversations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationMessage;
use App\Modules\Nexo\Privacy\SensitiveDataRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Consulta e exclusão do histórico pela própria pessoa (ADR-0008 §2.6).
 *
 * Funciona mesmo com a IA fora do ar e com o histórico desligado ("manter
 * inativo"): os dados são da pessoa e continuam sob o controle dela.
 */
class ConversationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:200']]);

        $conversations = Conversation::ownedBy($request->user())
            ->withCount('messages')
            ->when(filled($validated['q'] ?? null), fn ($query) => $query->whereHas('messages', fn ($m) => $m
                ->whereRaw("content_tsv @@ websearch_to_tsquery('portuguese', ?)", [$validated['q']])))
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $conversations->map(fn (Conversation $c) => $this->summary($c))]);
    }

    public function show(Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $messages = $conversation->messages()->orderBy('created_at')->limit(500)->get();

        return response()->json(['data' => [
            ...$this->summary($conversation->loadCount('messages')),
            'messages' => $messages->map(fn (ConversationMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'masked' => $m->redaction_count,
                'created_at' => $m->created_at->toIso8601String(),
            ]),
        ]]);
    }

    public function update(Request $request, Conversation $conversation, SensitiveDataRedactor $redactor): JsonResponse
    {
        Gate::authorize('update', $conversation);

        $title = $request->validate(['title' => ['required', 'string', 'max:120']])['title'];
        $conversation->update(['title' => $redactor->redact($title)->text]);

        return response()->json(['data' => $this->summary($conversation->loadCount('messages'))]);
    }

    public function destroy(Conversation $conversation): Response
    {
        Gate::authorize('delete', $conversation);

        $conversation->delete();
        $this->audit->record('nexo.conversation.deleted', $conversation->user, $conversation);

        return response()->noContent();
    }

    /** Apaga todo o histórico. Exige confirmação explícita. */
    public function destroyAll(Request $request): JsonResponse
    {
        $request->validate(['confirm' => ['accepted']]);

        $deleted = Conversation::ownedBy($request->user())->delete();
        $this->audit->record('nexo.history.cleared', $request->user(), metadata: ['conversations' => $deleted]);

        return response()->json(['data' => ['deleted_conversations' => $deleted]]);
    }

    public function destroyMessage(Conversation $conversation, ConversationMessage $message): Response
    {
        Gate::authorize('delete', $conversation);

        $message->delete();
        if (! $conversation->messages()->exists()) {
            $conversation->delete();
        }

        return response()->noContent();
    }

    private function summary(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'messages_count' => $conversation->messages_count,
            'last_message_at' => $conversation->last_message_at->toIso8601String(),
            'expires_at' => $conversation->expires_at?->toIso8601String(),
        ];
    }
}
