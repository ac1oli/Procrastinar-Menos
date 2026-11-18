<?php

namespace App\Modules\Privacy;

use App\Models\User;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\ConsentEvent;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationMessage;
use App\Modules\Conversations\Models\NexoPreference;
use App\Modules\Devices\Models\Device;
use App\Modules\Goals\Models\Goal;
use App\Modules\Memory\Models\Memory;
use App\Modules\Memory\Models\MemorySuggestion;
use App\Modules\Routine\Models\RoutineBlock;
use RuntimeException;
use ZipArchive;

/**
 * Monta o pacote de portabilidade dos dados da conta (LGPD art. 18, V): perfil, rotina,
 * objetivos, dispositivos e tudo do NEXO.
 *
 * JSON para máquinas + Markdown legível. Não entram: senha (hash), tokens de dispositivo,
 * embeddings (cálculos derivados do texto, que já está no pacote) e auditoria.
 * Deve rodar com o escopo do usuário definido (RLS).
 */
final class NexoDataExporter
{
    /** @return array<string, mixed> */
    public function collect(User $user): array
    {
        $iso = fn ($date) => $date?->toIso8601String();

        return [
            'account' => [
                'name' => $user->name, 'email' => $user->email, 'timezone' => $user->timezone,
                'created_at' => $iso($user->created_at), 'updated_at' => $iso($user->updated_at),
            ],
            'routine_blocks' => RoutineBlock::ownedBy($user)->orderBy('start_time')->get()->map(fn (RoutineBlock $b) => [
                'title' => $b->title, 'category' => $b->category->value, 'days_of_week' => $b->days_of_week,
                'start_time' => substr((string) $b->start_time, 0, 5), 'end_time' => substr((string) $b->end_time, 0, 5),
                'notes' => $b->notes, 'is_active' => $b->is_active, 'created_at' => $iso($b->created_at),
            ])->all(),
            'goals' => Goal::ownedBy($user)->orderBy('created_at')->get()->map(fn (Goal $g) => [
                'title' => $g->title, 'description' => $g->description, 'type' => $g->type->value,
                'status' => $g->status->value, 'target_date' => $g->target_date?->toDateString(), 'created_at' => $iso($g->created_at),
            ])->all(),
            // Sem tokens nem datas de expiração de credencial.
            'devices' => Device::ownedBy($user)->orderBy('created_at')->get()->map(fn (Device $d) => [
                'name' => $d->name, 'platform' => $d->platform->value, 'monitoring_enabled' => $d->monitoring_enabled,
                'created_at' => $iso($d->created_at), 'last_seen_at' => $iso($d->last_seen_at), 'revoked_at' => $iso($d->revoked_at),
            ])->all(),
            'consents' => [
                'current' => Consent::ownedBy($user)->get()->map(fn (Consent $c) => [
                    'scope' => $c->scope->value, 'granted' => $c->granted,
                    'policy_version' => $c->policy_version, 'updated_at' => $iso($c->updated_at),
                ])->all(),
                'history' => ConsentEvent::ownedBy($user)->orderBy('created_at')->get()->map(fn (ConsentEvent $e) => [
                    'scope' => $e->scope->value, 'action' => $e->action, 'policy_version' => $e->policy_version,
                    'data_action' => $e->data_action?->value, 'created_at' => $iso($e->created_at),
                ])->all(),
            ],
            'preferences' => ['history_retention_days' => NexoPreference::historyRetentionDays($user)],
            'conversations' => Conversation::ownedBy($user)->orderBy('created_at')->with(['messages' => fn ($q) => $q->orderBy('created_at')])
                ->get()->map(fn (Conversation $c) => [
                    'id' => $c->id, 'title' => $c->title, 'created_at' => $iso($c->created_at),
                    'last_message_at' => $iso($c->last_message_at), 'expires_at' => $iso($c->expires_at),
                    'messages' => $c->messages->map(fn (ConversationMessage $m) => [
                        'role' => $m->role, 'content' => $m->content, 'masked_items' => $m->redaction_count,
                        'created_at' => $iso($m->created_at),
                    ])->all(),
                ])->all(),
            'memories' => Memory::ownedBy($user)->orderBy('created_at')->get()->map(fn (Memory $m) => [
                'id' => $m->id, 'kind' => $m->kind->value, 'content' => $m->content,
                'sensitivity' => $m->sensitivity->value, 'origin' => $m->origin->value,
                'evidence_level' => $m->evidence_level->value, 'confidence' => $m->confidence->value,
                'status' => $m->status->value, 'pinned' => $m->pinned,
                'source_conversation_id' => $m->source_conversation_id,
                'created_at' => $iso($m->created_at), 'confirmed_at' => $iso($m->confirmed_at),
                'last_used_at' => $iso($m->last_used_at), 'last_reviewed_at' => $iso($m->last_reviewed_at),
            ])->all(),
            'memory_suggestions' => MemorySuggestion::ownedBy($user)->pending()->get()->map(fn (MemorySuggestion $s) => [
                'kind' => $s->kind->value, 'content' => $s->content, 'rationale' => $s->rationale,
                'created_at' => $iso($s->created_at), 'expires_at' => $iso($s->expires_at),
            ])->all(),
        ];
    }

    /** Gera o ZIP em memória e devolve os bytes. */
    public function zip(User $user): string
    {
        $data = $this->collect($user);
        $path = tempnam(sys_get_temp_dir(), 'pm-export-');
        $zip = new ZipArchive;

        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Não foi possível criar o pacote de exportação.');
            }

            $json = fn ($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $zip->addFromString('LEIAME.md', $this->readme());
            $zip->addFromString('conta/perfil.json', $json($data['account']));
            $zip->addFromString('planejamento/rotina.json', $json($data['routine_blocks']));
            $zip->addFromString('planejamento/objetivos.json', $json($data['goals']));
            $zip->addFromString('dispositivos/dispositivos.json', $json($data['devices']));
            $zip->addFromString('nexo/consentimentos.json', $json($data['consents']));
            $zip->addFromString('nexo/preferencias.json', $json($data['preferences']));
            $zip->addFromString('nexo/conversas.json', $json($data['conversations']));
            $zip->addFromString('nexo/memorias.json', $json($data['memories']));
            $zip->addFromString('nexo/sugestoes_pendentes.json', $json($data['memory_suggestions']));
            $zip->addFromString('nexo/memorias.md', $this->memoriesMarkdown($data['memories']));
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private function readme(): string
    {
        return <<<'MD'
        # Seus dados — Procrastinar Menos

        Gerado a pedido seu. Este arquivo é pessoal: guarde-o com cuidado.

        - `conta/perfil.json`: nome de exibição, e-mail, fuso horário e datas da conta.
        - `planejamento/rotina.json` e `planejamento/objetivos.json`: blocos de rotina e objetivos.
        - `dispositivos/dispositivos.json`: dispositivos vinculados (sem tokens).
        - `nexo/consentimentos.json`: autorizações atuais e o histórico de decisões, com a versão do texto aceito.
        - `nexo/preferencias.json`: prazo de retenção do histórico.
        - `nexo/conversas.json`: conversas guardadas. Dados como CPF, cartões, e-mails e senhas foram mascarados
          antes de serem gravados; `masked_items` indica quantos em cada mensagem.
        - `nexo/memorias.json` e `nexo/memorias.md`: memórias de longo prazo com origem, evidência, confiança e datas.
        - `nexo/sugestoes_pendentes.json`: sugestões do NEXO ainda não confirmadas.

        Não incluídos: senha, tokens de dispositivo, embeddings (cálculos derivados do texto, que já está aqui)
        e registros de auditoria.
        MD;
    }

    /** @param list<array<string, mixed>> $memories */
    private function memoriesMarkdown(array $memories): string
    {
        $lines = ['# Suas memórias no NEXO', ''];
        foreach ($memories as $m) {
            $lines[] = "- **{$m['kind']}** — {$m['content']}";
            $lines[] = "  - origem: {$m['origin']}; evidência: {$m['evidence_level']}; confiança: {$m['confidence']}; status: {$m['status']}";
            $lines[] = "  - criada em {$m['created_at']}";
        }

        return implode("\n", $lines)."\n";
    }
}
