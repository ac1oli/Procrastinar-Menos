<?php

/*
| Política de retenção de dados (docs/security/retencao-de-dados.md, ADR-0006).
| Aplicada diariamente por `php artisan audit:apply-retention`.
*/

return [

    'audit_logs' => [
        // Após este prazo o IP é apagado (permanece só o evento).
        'ip_address_days' => 90,

        // Após este prazo o registro de auditoria é excluído.
        'delete_after_days' => 365,

        /*
        | Ações mantidas (anonimizadas) quando a conta é excluída: necessárias
        | para segurança e para demonstrar o cumprimento da LGPD. As demais
        | são apagadas junto com a conta.
        */
        'retained_on_account_deletion' => [
            'auth.registered',
            'auth.login',
            'device.linked',
            'device.revoked',
            'device.token_reissued',
            'device.token_rotated',
            'device.monitoring_enabled',
            'device.monitoring_disabled',
            'account.deleted',
        ],

        /*
        | Ações excluídas junto com a conta. Todo tipo de evento precisa estar em
        | uma das duas listas (verificado por Tests\Feature\Audit\AuditClassificationTest).
        | Eventos do NEXO nunca são retidos e nunca carregam conteúdo (ADR-0008).
        */
        'not_retained_on_account_deletion' => [
            'auth.logout',
            'nexo.consent.granted',
            'nexo.consent.revoked',
            'nexo.consent.declined',
            'nexo.history.cleared',
            'nexo.conversation.deleted',
            'nexo.memory.created',
            'nexo.memory.updated',
            'nexo.memory.deleted',
            'nexo.memory.cleared',
            'nexo.suggestion.accepted',
            'nexo.export.requested',
            'nexo.export.downloaded',
        ],
    ],

];
