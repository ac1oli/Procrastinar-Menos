<?php

namespace App\Modules\Conversations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Conversations\ConversationRecorder;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\NexoPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Retenção do histórico escolhida pela pessoa (D2). */
class NexoPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request)]);
    }

    public function update(Request $request, ConversationRecorder $recorder): JsonResponse
    {
        $days = $this->validatedDays($request, 'history_retention_days');

        NexoPreference::updateOrCreate(['user_id' => $request->user()->getKey()], ['history_retention_days' => $days]);
        $recorder->applyRetention($request->user(), $days);

        return response()->json(['data' => $this->payload($request)]);
    }

    /** Quantas conversas um prazo apagaria na próxima execução, para avisar antes de mudar. */
    public function preview(Request $request): JsonResponse
    {
        $days = $this->validatedDays($request, 'days');

        $affected = $days === null ? 0 : Conversation::ownedBy($request->user())
            ->where('last_message_at', '<', now()->subDays($days))
            ->count();

        return response()->json(['data' => ['days' => $days, 'conversations_to_delete' => $affected]]);
    }

    private function validatedDays(Request $request, string $field): ?int
    {
        $allowed = array_values(array_filter(config('nexo.history.retention_options')));
        $value = $request->validate([$field => ['present', 'nullable', 'integer', Rule::in($allowed)]])[$field];

        return $value === null ? null : (int) $value;
    }

    private function payload(Request $request): array
    {
        return [
            'history_retention_days' => NexoPreference::historyRetentionDays($request->user()),
            'history_retention_options' => config('nexo.history.retention_options'),
        ];
    }
}
