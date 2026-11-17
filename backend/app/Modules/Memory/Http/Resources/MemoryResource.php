<?php

namespace App\Modules\Memory\Http\Resources;

use App\Modules\Memory\Models\EmbeddingModel;
use App\Modules\Memory\Models\Memory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Memória com todos os metadados de origem e confiança (R6). O vetor nunca
 * é exposto; só se o índice semântico está pronto.
 *
 * @mixin Memory
 */
class MemoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lastActivity = collect([$this->last_used_at, $this->last_reviewed_at, $this->confirmed_at, $this->created_at])->filter()->max();
        $embedding = $this->relationLoaded('embedding') ? $this->embedding : null;

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'content' => $this->content,
            'sensitivity' => $this->sensitivity->value,
            'origin' => $this->origin->value,
            'evidence_level' => $this->evidence_level->value,
            'confidence' => $this->confidence->value,
            'status' => $this->status->value,
            'pinned' => $this->pinned,
            'source' => [
                'conversation_id' => $this->source_conversation_id,
                'message_id' => $this->source_message_id,
            ],
            'semantic_index' => $embedding
                && $embedding->content_hash === $this->content_hash
                && $embedding->embedding_model_id === EmbeddingModel::active()?->id ? 'ready' : 'pending',
            'created_at' => $this->created_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'last_reviewed_at' => $this->last_reviewed_at?->toIso8601String(),
            'review_due_at' => $lastActivity?->copy()->addDays((int) config('nexo.memory.review_after_days'))->toIso8601String(),
        ];
    }
}
