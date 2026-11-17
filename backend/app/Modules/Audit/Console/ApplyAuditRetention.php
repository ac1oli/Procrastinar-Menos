<?php

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('audit:apply-retention')]
#[Description('Aplica a política de retenção da auditoria (anonimiza IPs e exclui registros vencidos).')]
class ApplyAuditRetention extends Command
{
    public function handle(): int
    {
        $ipDays = (int) config('retention.audit_logs.ip_address_days');
        $deleteDays = (int) config('retention.audit_logs.delete_after_days');

        $anonymized = AuditLog::whereNotNull('ip_address')
            ->where('created_at', '<', now()->subDays($ipDays))
            ->update(['ip_address' => null]);

        $deleted = AuditLog::where('created_at', '<', now()->subDays($deleteDays))->delete();

        $this->info("IPs anonimizados: {$anonymized}; registros excluídos: {$deleted}.");

        return self::SUCCESS;
    }
}
