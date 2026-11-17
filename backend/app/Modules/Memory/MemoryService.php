<?php

namespace App\Modules\Memory;

use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Memory\Enums\EvidenceLevel;
use App\Modules\Memory\Enums\MemoryConfidence;
use App\Modules\Memory\Enums\MemoryKind;
use App\Modules\Memory\Enums\MemoryOrigin;
use App\Modules\Memory\Enums\MemoryStatus;
use App\Modules\Memory\Jobs\EmbedMemory;
use App\Modules\Memory\Models\Memory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Ciclo de vida das memórias de longo prazo (ADR-0008 §2.3).
 *
 * Criar exige nexo.memory. Consultar, editar, arquivar e excluir continuam
 * disponíveis mesmo com a memória desligada: os dados são da pessoa.
 * A auditoria nunca recebe o conteúdo.
 */
final class MemoryService
{
    public function __construct(
        private readonly MemoryGuard $guard,
        private readonly ConsentService $consents,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{kind: string, content: string, sensitive?: bool, pinned?: bool} $data */
    public function create(User $user, array $data, MemoryOrigin $origin = MemoryOrigin::UserCreated, array $source = []): Memory
    {
        if (! $this->consents->allows($user, ConsentScope::Memory)) {
            throw new ConflictHttpException('A memória de longo prazo está desligada. Ative-a para guardar memórias.');
        }

        $content = $this->normalize($data['content']);
        $sensitivity = $this->guard->forManualContent($user, $content, (bool) ($data['sensitive'] ?? false));
        $pinned = (bool) ($data['pinned'] ?? false);
        if ($pinned) {
            $this->assertPinnedLimit($user);
        }

        $memory = DB::transaction(fn () => Memory::create([
            'user_id' => $user->getKey(),
            'kind' => MemoryKind::from($data['kind']),
            'content' => $content,
            'content_hash' => Memory::hashContent($content),
            'sensitivity' => $sensitivity,
            'origin' => $origin,
            // Escrita ou confirmada pela pessoa: declarada, confiança alta (política §3).
            'evidence_level' => EvidenceLevel::Declared,
            'confidence' => MemoryConfidence::High,
            'status' => MemoryStatus::Active,
            'pinned' => $pinned,
            'source_conversation_id' => $source['conversation_id'] ?? null,
            'source_message_id' => $source['message_id'] ?? null,
            'confirmed_at' => now(),
        ]));

        EmbedMemory::dispatch($user->getKey(), $memory->id)->afterCommit();
        $this->audit->record('nexo.memory.created', $user, $memory, ['kind' => $memory->kind->value, 'origin' => $origin->value]);

        return $memory;
    }

    /** @param array{kind?: string, content?: string, sensitive?: bool, pinned?: bool, status?: string} $data */
    public function update(User $user, Memory $memory, array $data): Memory
    {
        $changes = [];

        if (array_key_exists('content', $data) || array_key_exists('sensitive', $data)) {
            $content = $this->normalize($data['content'] ?? $memory->content);
            $changes['sensitivity'] = $this->guard->forManualContent($user, $content, (bool) ($data['sensitive'] ?? $memory->isSensitive()));
            $changes['content'] = $content;
            $changes['content_hash'] = Memory::hashContent($content);
            // Editada pela pessoa: passa a ser declarada por ela, com confiança alta.
            $changes['evidence_level'] = EvidenceLevel::Declared;
            $changes['confidence'] = MemoryConfidence::High;
        }
        if (isset($data['kind'])) {
            $changes['kind'] = MemoryKind::from($data['kind']);
        }
        if (isset($data['status'])) {
            $changes['status'] = MemoryStatus::from($data['status']);
        }
        if (array_key_exists('pinned', $data)) {
            if ($data['pinned'] && ! $memory->pinned) {
                $this->assertPinnedLimit($user);
            }
            $changes['pinned'] = (bool) $data['pinned'];
        }

        $changes['last_reviewed_at'] = now();
        $contentChanged = isset($changes['content_hash']) && $changes['content_hash'] !== $memory->content_hash;

        // Editar substitui o conteúdo: nenhuma versão anterior é guardada.
        DB::transaction(fn () => $memory->update($changes));

        if ($contentChanged) {
            EmbedMemory::dispatch($user->getKey(), $memory->id)->afterCommit();
        }
        $this->audit->record('nexo.memory.updated', $user, $memory, ['fields' => array_keys($data)]);

        return $memory->refresh();
    }

    /** A pessoa confirma que a memória continua válida (D3). */
    public function review(Memory $memory): Memory
    {
        $memory->update(['status' => MemoryStatus::Active, 'last_reviewed_at' => now()]);

        return $memory->refresh();
    }

    public function delete(User $user, Memory $memory): void
    {
        $memory->delete();
        $this->audit->record('nexo.memory.deleted', $user, $memory);
    }

    public function deleteAll(User $user): int
    {
        $deleted = Memory::ownedBy($user)->delete();
        $this->audit->record('nexo.memory.cleared', $user, metadata: ['memories' => $deleted]);

        return $deleted;
    }

    private function normalize(string $content): string
    {
        $content = trim(preg_replace('/\s+/u', ' ', $content));
        if ($content === '' || mb_strlen($content) > (int) config('nexo.memory.max_content_chars')) {
            throw ValidationException::withMessages(['content' => 'A memória deve ter entre 1 e 500 caracteres.']);
        }

        return $content;
    }

    private function assertPinnedLimit(User $user): void
    {
        if (Memory::ownedBy($user)->where('pinned', true)->count() >= (int) config('nexo.memory.max_pinned')) {
            throw ValidationException::withMessages([
                'pinned' => 'Você já tem '.config('nexo.memory.max_pinned').' memórias fixadas. Desafixe uma antes.',
            ]);
        }
    }
}
