<?php

namespace App\Modules\Memory\Models;

use App\Modules\Memory\Enums\EvidenceLevel;
use App\Modules\Memory\Enums\MemoryConfidence;
use App\Modules\Memory\Enums\MemoryKind;
use App\Modules\Memory\Enums\MemoryOrigin;
use App\Modules\Memory\Enums\MemorySensitivity;
use App\Modules\Memory\Enums\MemoryStatus;
use App\Modules\Memory\Policies\MemoryPolicy;
use App\Modules\Shared\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Memória de longo prazo autorizada (ADR-0008 §2.3). */
#[Fillable([
    'user_id', 'kind', 'content', 'content_hash', 'sensitivity', 'origin', 'evidence_level', 'confidence',
    'status', 'pinned', 'source_conversation_id', 'source_message_id', 'confirmed_at', 'last_used_at', 'last_reviewed_at',
])]
#[Hidden(['content_tsv'])]
#[UsePolicy(MemoryPolicy::class)]
class Memory extends Model
{
    use BelongsToUser, HasUuids;

    protected function casts(): array
    {
        return [
            'kind' => MemoryKind::class,
            'sensitivity' => MemorySensitivity::class,
            'origin' => MemoryOrigin::class,
            'evidence_level' => EvidenceLevel::class,
            'confidence' => MemoryConfidence::class,
            'status' => MemoryStatus::class,
            'pinned' => 'boolean',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
        ];
    }

    public function embedding(): HasOne
    {
        return $this->hasOne(MemoryEmbedding::class);
    }

    /** Hash do conteúdo normalizado: o vetor só vale se foi gerado deste texto. */
    public static function hashContent(string $content): string
    {
        return hash('sha256', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $content))));
    }

    public function isSensitive(): bool
    {
        return $this->sensitivity === MemorySensitivity::Sensitive;
    }
}
