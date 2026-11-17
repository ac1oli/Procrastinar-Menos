<?php

namespace App\Modules\Audit;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Registra operações relevantes para segurança e privacidade
 * (login, vínculo/revogação de dispositivos, mudanças de consentimento…).
 *
 * Nunca inclua em $metadata senhas, tokens ou conteúdo pessoal.
 */
class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    public function record(string $action, ?User $user, ?Model $subject = null, array $metadata = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => $user?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata ?: null,
            'ip_address' => $this->request->ip(),
            'created_at' => now(),
        ]);
    }
}
