<?php

namespace App\Modules\Privacy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\ConsentEvent;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationMessage;
use App\Modules\Conversations\Models\NexoPreference;
use App\Modules\Devices\Models\Device;
use App\Modules\Goals\Enums\GoalStatus;
use App\Modules\Goals\Models\Goal;
use App\Modules\Memory\Enums\MemorySensitivity;
use App\Modules\Memory\Enums\MemoryStatus;
use App\Modules\Memory\Models\Memory;
use App\Modules\Memory\Models\MemorySuggestion;
use App\Modules\Privacy\Models\DataExport;
use App\Modules\Routine\Models\RoutineBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventário dos dados da conta para a central "Meus dados" (FASE 4).
 *
 * Só contagens e datas, nunca conteúdo. As contagens também alimentam as prévias das
 * ações destrutivas: a interface mostra o que será afetado com números do backend, e as
 * consequências descritas seguem as chaves estrangeiras reais:
 * - apagar o histórico apaga mensagens e as sugestões nascidas das conversas; memórias ficam
 *   (perdem só o vínculo com a conversa);
 * - apagar as memórias apaga também os índices semânticos; conversas e sugestões ficam;
 * - excluir a conta apaga tudo, exceto os eventos de segurança listados em
 *   config/retention.php, que ficam anonimizados.
 */
class DataSummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $memories = Memory::ownedBy($user);
        $iso = fn ($date) => $date?->toIso8601String();
        $lastExport = DataExport::ownedBy($user)->latest('created_at')->first();

        return response()->json(['data' => [
            'conversations' => [
                'count' => Conversation::ownedBy($user)->count(),
                'messages' => ConversationMessage::ownedBy($user)->count(),
                'oldest_at' => $iso(Conversation::ownedBy($user)->oldest('created_at')->first()?->created_at),
                'retention_days' => NexoPreference::historyRetentionDays($user),
            ],
            'memories' => [
                'count' => (clone $memories)->count(),
                'active' => (clone $memories)->where('status', MemoryStatus::Active)->count(),
                'needs_review' => (clone $memories)->where('status', MemoryStatus::NeedsReview)->count(),
                'archived' => (clone $memories)->where('status', MemoryStatus::Archived)->count(),
                'pinned' => (clone $memories)->where('pinned', true)->count(),
                'sensitive' => (clone $memories)->where('sensitivity', MemorySensitivity::Sensitive)->count(),
                'from_conversations' => (clone $memories)->whereNotNull('source_conversation_id')->count(),
            ],
            'suggestions' => [
                'pending' => MemorySuggestion::ownedBy($user)->pending()->count(),
            ],
            'planning' => [
                'routine_blocks' => RoutineBlock::ownedBy($user)->count(),
                'goals' => Goal::ownedBy($user)->count(),
                'goals_active' => Goal::ownedBy($user)->where('status', GoalStatus::Active)->count(),
            ],
            'devices' => [
                'active' => Device::ownedBy($user)->whereNull('revoked_at')->count(),
                'revoked' => Device::ownedBy($user)->whereNotNull('revoked_at')->count(),
            ],
            'consents' => [
                'granted' => Consent::ownedBy($user)->where('granted', true)->count(),
                'denied' => Consent::ownedBy($user)->where('granted', false)->count(),
                'events' => ConsentEvent::ownedBy($user)->count(),
            ],
            'security_log' => [
                'events' => AuditLog::where('user_id', $user->getKey())->count(),
                // Ficam anonimizados (sem usuário, IP ou detalhes) se a conta for excluída.
                'kept_anonymized_on_deletion' => AuditLog::where('user_id', $user->getKey())
                    ->whereIn('action', config('retention.audit_logs.retained_on_account_deletion'))->count(),
                'retention_days' => (int) config('retention.audit_logs.delete_after_days'),
                'ip_retention_days' => (int) config('retention.audit_logs.ip_address_days'),
            ],
            'last_export' => $lastExport ? [
                'status' => $lastExport->status,
                'created_at' => $iso($lastExport->created_at),
            ] : null,
        ]]);
    }
}
