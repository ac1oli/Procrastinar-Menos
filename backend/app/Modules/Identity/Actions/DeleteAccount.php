<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Devices\Models\Device;
use App\Modules\Privacy\Models\DataExport;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Exclusão de conta (LGPD art. 18, VI) conforme docs/security/retencao-de-dados.md.
 *
 * - Dados pessoais e de uso: excluídos (cascata em devices, routine_blocks, goals…).
 * - NEXO (ADR-0008): consentimentos, histórico, memórias, embeddings, sugestões e
 *   registros de uso saem pela cascata de users (as FKs ignoram a RLS); arquivos
 *   de exportação são apagados aqui. O diário de exclusões guarda só os UUIDs,
 *   por 35 dias, para reaplicar a exclusão se um backup for restaurado.
 * - Tokens de usuário e de dispositivos, e sessões: excluídos.
 * - Auditoria: só as ações de config('retention.audit_logs.retained_on_account_deletion')
 *   permanecem, sem user_id, IP, metadata nem subject_id; o restante é excluído.
 * - Um evento `account.deleted` sem identificadores registra que a exclusão ocorreu.
 */
final class DeleteAccount
{
    public function __invoke(User $user): void
    {
        $retained = config('retention.audit_logs.retained_on_account_deletion');

        DB::transaction(function () use ($user, $retained) {
            $deviceIds = $user->devices()->pluck('id');

            // Tokens são polimórficos: não há cascata pela FK.
            PersonalAccessToken::query()
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('tokenable_type', Device::class)->whereIn('tokenable_id', $deviceIds))
                    ->orWhere(fn ($q) => $q->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->getKey())))
                ->delete();

            DB::table('sessions')->where('user_id', $user->getKey())->delete();

            // Arquivos de exportação ficam fora do banco: apagados explicitamente.
            DataExport::ownedBy($user)->get()->each(fn (DataExport $export) => $export->discardFile());

            AuditLog::where('user_id', $user->getKey())->whereNotIn('action', $retained)->delete();
            AuditLog::where('user_id', $user->getKey())->update([
                'user_id' => null,
                'ip_address' => null,
                'metadata' => null,
                'subject_id' => null,
            ]);

            $user->delete();

            AuditLog::create(['action' => 'account.deleted', 'created_at' => now()]);
        });
    }
}
