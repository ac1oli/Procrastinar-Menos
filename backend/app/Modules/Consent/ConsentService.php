<?php

namespace App\Modules\Consent;

use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Consent\Enums\DataAction;
use App\Modules\Consent\Events\ConsentRevoked;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\ConsentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fonte única de verdade sobre o que a pessoa autorizou (ADR-0008 §2.2, §7).
 *
 * Nada fora daqui decide se um dado pode ser guardado ou usado pelo NEXO.
 * Nunca confia em flags enviadas pelo cliente.
 */
final class ConsentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function allows(User $user, ConsentScope $scope): bool
    {
        return in_array($scope, $this->granted($user), true);
    }

    /** @return list<ConsentScope> */
    public function granted(User $user): array
    {
        return Consent::ownedBy($user)
            ->where('granted', true)
            ->pluck('scope')
            ->all();
    }

    /** @return array<string, Consent> estado atual indexado pelo valor do escopo */
    public function states(User $user): array
    {
        return Consent::ownedBy($user)->get()->keyBy(fn (Consent $c) => $c->scope->value)->all();
    }

    public function grant(User $user, ConsentScope $scope, string $policyVersion): void
    {
        if (! $scope->isAvailable()) {
            throw ValidationException::withMessages(['scope' => 'Esta autorização ainda não está disponível.']);
        }
        if ($policyVersion !== config('nexo.memory_policy_version')) {
            throw ValidationException::withMessages([
                'policy_version' => 'A política de memória mudou. Leia a versão atual antes de autorizar.',
            ]);
        }

        $missing = array_filter($scope->requires(), fn (ConsentScope $r) => ! $this->allows($user, $r));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'scope' => 'Antes, autorize: '.implode(', ', array_map(fn ($s) => $s->value, $missing)).'.',
            ]);
        }

        if ($this->allows($user, $scope)) {
            return;
        }

        DB::transaction(function () use ($user, $scope, $policyVersion) {
            $this->store($user, $scope, true, $policyVersion);
            $this->event($user, $scope, 'granted', $policyVersion, null);
        });

        $this->audit->record('nexo.consent.granted', $user, metadata: ['scope' => $scope->value]);
    }

    /**
     * Revoga (ou recusa, se nunca decidido) uma autorização.
     *
     * O uso para imediatamente. Se o escopo guarda dados e estava concedido, a
     * pessoa precisa escolher excluir ou manter inativo. Escopos dependentes
     * são revogados junto, com a mesma escolha.
     */
    public function revoke(User $user, ConsentScope $scope, ?DataAction $dataAction): void
    {
        $wasGranted = $this->allows($user, $scope);

        if ($wasGranted && $scope->holdsData() && $dataAction === null) {
            throw ValidationException::withMessages([
                'data_action' => 'Escolha o que fazer com os dados já guardados: excluir ou manter inativos.',
            ]);
        }

        DB::transaction(function () use ($user, $scope, $dataAction, $wasGranted) {
            foreach ($scope->dependents() as $dependent) {
                if ($this->allows($user, $dependent)) {
                    $this->revoke($user, $dependent, $dependent->holdsData() ? $dataAction : null);
                }
            }

            $this->store($user, $scope, false, null);
            $action = $wasGranted ? 'revoked' : 'declined';
            $this->event($user, $scope, $action, null, $wasGranted ? $dataAction : null);

            if ($wasGranted) {
                event(new ConsentRevoked($user, $scope, $scope->holdsData() ? $dataAction : null));
            }
        });

        $this->audit->record(
            $wasGranted ? 'nexo.consent.revoked' : 'nexo.consent.declined',
            $user,
            metadata: array_filter(['scope' => $scope->value, 'data_action' => $dataAction?->value]),
        );
    }

    private function store(User $user, ConsentScope $scope, bool $granted, ?string $policyVersion): void
    {
        Consent::updateOrCreate(
            ['user_id' => $user->getKey(), 'scope' => $scope],
            ['granted' => $granted, 'policy_version' => $policyVersion],
        );
    }

    private function event(User $user, ConsentScope $scope, string $action, ?string $policyVersion, ?DataAction $dataAction): void
    {
        ConsentEvent::create([
            'user_id' => $user->getKey(),
            'scope' => $scope,
            'action' => $action,
            'policy_version' => $policyVersion,
            'data_action' => $dataAction,
            'created_at' => now(),
        ]);
    }
}
