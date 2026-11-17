<?php

namespace App\Modules\Nexo\Context;

use App\Models\User;
use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Goals\Enums\GoalStatus;
use App\Modules\Goals\Models\Goal;
use App\Modules\Routine\Models\RoutineBlock;
use Carbon\CarbonImmutable;

/**
 * Recorte pequeno e útil da rotina e dos objetivos, montado pelo backend sem
 * IA e só com o consentimento de cada fonte (R12). Descrições e notas ficam
 * de fora (minimização).
 */
final class ProfileSnapshot
{
    private const MAX_GOALS = 3;

    private const MAX_BLOCKS = 12;

    public function __construct(private readonly ConsentService $consents) {}

    /** @return array{routine_today: list<string>, goals: list<string>}|null */
    public function for(User $user): ?array
    {
        $routine = $this->consents->allows($user, ConsentScope::ContextRoutine);
        $goals = $this->consents->allows($user, ConsentScope::ContextGoals);

        if (! $routine && ! $goals) {
            return null;
        }

        return [
            'routine_today' => $routine ? $this->routineToday($user) : [],
            'goals' => $goals ? $this->activeGoals($user) : [],
        ];
    }

    /** @return list<string> */
    private function routineToday(User $user): array
    {
        $today = CarbonImmutable::now($user->timezone)->dayOfWeekIso;

        return RoutineBlock::ownedBy($user)
            ->where('is_active', true)
            ->whereJsonContains('days_of_week', $today)
            ->orderBy('start_time')
            ->limit(self::MAX_BLOCKS)
            ->get()
            ->map(fn (RoutineBlock $b) => sprintf(
                '%s–%s %s (%s)',
                substr($b->start_time, 0, 5),
                substr($b->end_time, 0, 5),
                $b->title,
                $b->category->value,
            ))
            ->all();
    }

    /** @return list<string> */
    private function activeGoals(User $user): array
    {
        return Goal::ownedBy($user)
            ->where('status', GoalStatus::Active)
            ->orderByRaw('target_date ASC NULLS LAST')
            ->orderBy('created_at')
            ->limit(self::MAX_GOALS)
            ->get()
            ->map(fn (Goal $g) => $g->title.' ('.$g->type->value
                .($g->target_date ? ', prazo '.$g->target_date->format('d/m/Y') : '').')')
            ->all();
    }
}
