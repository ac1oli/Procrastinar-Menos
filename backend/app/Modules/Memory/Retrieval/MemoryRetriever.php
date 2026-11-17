<?php

namespace App\Modules\Memory\Retrieval;

use App\Models\User;
use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Memory\Casts\Vector;
use App\Modules\Memory\Enums\MemorySensitivity;
use App\Modules\Memory\Enums\MemoryStatus;
use App\Modules\Memory\Models\EmbeddingModel;
use App\Modules\Memory\Models\Memory;
use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Data\EmbeddingPurpose;
use App\Modules\Nexo\Exceptions\AiUnavailableException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Decide quais memórias entram no contexto de uma mensagem (ADR-0008 §2.5).
 *
 * Determinístico e barato: sem chamada ao modelo de chat. Fixadas entram
 * sempre; as demais vêm da busca híbrida (cosseno no pgvector + busca textual
 * em português), fundidas por Reciprocal Rank Fusion, ponderadas por
 * confiança e estado de revisão, cortadas por limiar, quantidade e orçamento.
 *
 * Único ponto de busca vetorial do sistema. Toda consulta filtra pelo usuário
 * recebido, e a RLS repete o filtro no banco.
 */
final class MemoryRetriever
{
    public function __construct(
        private readonly NexoGateway $gateway,
        private readonly ConsentService $consents,
    ) {}

    /** @return list<RetrievedMemory> */
    public function retrieve(User $user, string $message): array
    {
        if (! $this->consents->allows($user, ConsentScope::Memory)) {
            return [];
        }

        $config = config('nexo.memory.retrieval');
        $allowSensitive = $this->consents->allows($user, ConsentScope::MemorySensitive);

        $pinned = $this->usable($user, $allowSensitive)
            ->where('pinned', true)
            ->orderBy('created_at')
            ->limit((int) config('nexo.memory.max_pinned'))
            ->get()
            ->map(fn (Memory $m) => new RetrievedMemory($m, null, null))
            ->all();

        $vector = $this->vectorCandidates($user, $message, $allowSensitive, $config);
        $text = $this->textCandidates($user, $message, $allowSensitive, (int) $config['candidates']);

        $scores = [];
        foreach ([array_keys($vector), $text] as $ranking) {
            foreach ($ranking as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0) + 1 / ($config['rrf_k'] + $rank + 1);
            }
        }

        $memories = Memory::ownedBy($user)->whereKey(array_keys($scores))->get()->keyBy('id');
        $ranked = [];
        foreach ($scores as $id => $score) {
            $memory = $memories[$id] ?? null;
            if ($memory === null) {
                continue;
            }
            // Sensível só entra com evidência vetorial forte, nunca só por palavra.
            if ($memory->isSensitive() && ($vector[$id] ?? 0) < $config['sensitive_min_similarity']) {
                continue;
            }
            $weight = $memory->confidence->weight() * ($memory->status === MemoryStatus::NeedsReview ? 0.8 : 1.0);
            $ranked[] = new RetrievedMemory($memory, $score * $weight, $vector[$id] ?? null);
        }

        usort($ranked, fn (RetrievedMemory $a, RetrievedMemory $b) => $b->score <=> $a->score);

        return $this->withinBudget([...$pinned, ...array_slice($ranked, 0, (int) $config['max_results'])], (int) $config['budget_chars']);
    }

    /**
     * @return array<string, float> id => cosseno, em ordem de similaridade
     */
    private function vectorCandidates(User $user, string $message, bool $allowSensitive, array $config): array
    {
        $model = EmbeddingModel::active();
        if ($model === null) {
            return [];
        }

        try {
            $query = $this->gateway->embed([$message], EmbeddingPurpose::Query)->vectors[0];
        } catch (AiUnavailableException) {
            // Degrada para busca textual; a resposta não depende do embedding.
            return [];
        }

        $literal = Vector::literal($query);

        $rows = DB::transaction(function () use ($user, $model, $literal, $allowSensitive, $config) {
            // Com filtro por usuário, o HNSW precisa continuar a varredura (pgvector ≥ 0.8).
            DB::statement("SET LOCAL hnsw.iterative_scan = 'relaxed_order'");

            return DB::select(
                'SELECT m.id, 1 - (e.embedding <=> ?::vector) AS similarity
                   FROM memory_embeddings e
                   JOIN memories m ON m.id = e.memory_id AND m.user_id = e.user_id
                  WHERE e.user_id = ? AND e.embedding_model_id = ? AND e.content_hash = m.content_hash
                    AND m.status IN (?, ?) AND m.pinned = false'
                    .($allowSensitive ? '' : ' AND m.sensitivity = ?').'
                  ORDER BY e.embedding <=> ?::vector
                  LIMIT ?',
                [
                    $literal, $user->getKey(), $model->id,
                    MemoryStatus::Active->value, MemoryStatus::NeedsReview->value,
                    ...($allowSensitive ? [] : [MemorySensitivity::Normal->value]),
                    $literal, (int) $config['candidates'],
                ],
            );
        });

        $candidates = [];
        foreach ($rows as $row) {
            if ((float) $row->similarity >= $config['min_similarity']) {
                $candidates[$row->id] = (float) $row->similarity;
            }
        }

        return $candidates;
    }

    /** @return list<string> ids em ordem de relevância textual */
    private function textCandidates(User $user, string $message, bool $allowSensitive, int $limit): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($message), $words);
        $terms = array_slice(array_unique($words[0]), 0, 16);
        if ($terms === []) {
            return [];
        }

        // OU entre os termos: a pergunta raramente contém todas as palavras da memória.
        $query = implode(' | ', $terms);

        return $this->usable($user, $allowSensitive)
            ->where('pinned', false)
            ->whereRaw("content_tsv @@ to_tsquery('portuguese', ?)", [$query])
            ->orderByRaw("ts_rank(content_tsv, to_tsquery('portuguese', ?)) DESC", [$query])
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    private function usable(User $user, bool $allowSensitive): Builder
    {
        return Memory::ownedBy($user)
            ->whereIn('status', [MemoryStatus::Active, MemoryStatus::NeedsReview])
            ->when(! $allowSensitive, fn ($q) => $q->where('sensitivity', MemorySensitivity::Normal));
    }

    /**
     * @param  list<RetrievedMemory>  $memories
     * @return list<RetrievedMemory>
     */
    private function withinBudget(array $memories, int $budget): array
    {
        $kept = [];
        foreach ($memories as $item) {
            $budget -= mb_strlen($item->memory->content);
            if ($budget < 0) {
                break;
            }
            $kept[] = $item;
        }

        return $kept;
    }
}
