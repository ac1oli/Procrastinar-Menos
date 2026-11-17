<?php

namespace App\Modules\Nexo\Console;

use App\Models\User;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Memory\Enums\MemoryStatus;
use App\Modules\Memory\Models\Memory;
use App\Modules\Memory\Models\MemorySuggestion;
use App\Modules\Privacy\Models\DataExport;
use App\Modules\Shared\Database\DatabaseUserScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prazos de retenção do NEXO (ADR-0008 §6), aplicados um usuário por vez:
 * a conexão da aplicação só enxerga um usuário de cada vez (RLS).
 */
#[Signature('nexo:apply-retention')]
#[Description('Aplica a retenção do histórico, sugestões, exportações e diário de exclusões, e marca memórias para revisão.')]
class ApplyNexoRetention extends Command
{
    public function handle(): int
    {
        $totals = ['conversations' => 0];

        User::query()->select('id')->lazyById(200)->each(function (User $user) use (&$totals) {
            $deleted = DatabaseUserScope::run($user, fn () => DB::transaction(fn () => $this->applyFor($user)));

            foreach ($deleted as $key => $count) {
                $totals[$key] = ($totals[$key] ?? 0) + $count;
            }
        });

        // O diário acompanha a janela de rotação dos backups (D9).
        $totals['deletion_journal'] = (int) DB::scalar(
            'select purge_deletion_journal(?)',
            [(int) config('nexo.backup.deletion_journal_days')],
        );

        $this->info(collect($totals)->map(fn ($n, $k) => "{$k}: {$n}")->implode('; '));

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function applyFor(User $user): array
    {
        $reviewBefore = now()->subDays((int) config('nexo.memory.review_after_days'));

        $expiredExports = DataExport::ownedBy($user)->where('expires_at', '<', now())->get();
        $expiredExports->each(fn (DataExport $export) => $export->discardFile());

        return [
            'conversations' => Conversation::ownedBy($user)->where('expires_at', '<', now())->delete(),
            'suggestions' => MemorySuggestion::ownedBy($user)->where('expires_at', '<=', now())->delete(),
            'exports' => DataExport::ownedBy($user)->whereKey($expiredExports->modelKeys())->delete(),
            // Memórias não expiram (D3): sem uso nem revisão, pedem revisão.
            'memories_to_review' => Memory::ownedBy($user)
                ->where('status', MemoryStatus::Active)
                ->whereRaw('GREATEST(last_used_at, last_reviewed_at, confirmed_at, created_at) < ?', [$reviewBefore])
                ->update(['status' => MemoryStatus::NeedsReview]),
        ];
    }
}
