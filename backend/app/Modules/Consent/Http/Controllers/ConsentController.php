<?php

namespace App\Modules\Consent\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Consent\Enums\DataAction;
use App\Modules\Consent\Http\Requests\UpdateConsentRequest;
use App\Modules\Consent\Models\ConsentEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autorizações do NEXO.
 *
 * `granted: null` significa "nunca decidido": o dashboard pergunta no primeiro
 * acesso, sem nenhuma opção pré-marcada (D1).
 */
class ConsentController extends Controller
{
    public function __construct(private readonly ConsentService $consents) {}

    public function index(Request $request): JsonResponse
    {
        $states = $this->consents->states($request->user());

        $scopes = array_map(function (ConsentScope $scope) use ($states) {
            $state = $states[$scope->value] ?? null;

            return [
                'scope' => $scope->value,
                'granted' => $state?->granted,
                'decided_at' => $state?->updated_at?->toIso8601String(),
                'requires' => array_map(fn ($s) => $s->value, $scope->requires()),
                'holds_data' => $scope->holdsData(),
                'available' => $scope->isAvailable(),
            ];
        }, ConsentScope::cases());

        return response()->json(['data' => [
            'policy_version' => config('nexo.memory_policy_version'),
            'scopes' => $scopes,
            'pending' => array_values(array_map(
                fn (array $s) => $s['scope'],
                array_filter($scopes, fn (array $s) => $s['granted'] === null && $s['available']),
            )),
        ]]);
    }

    public function update(UpdateConsentRequest $request, string $scope): JsonResponse
    {
        $scope = ConsentScope::tryFrom($scope) ?? abort(404);

        if ($request->boolean('granted')) {
            $this->consents->grant($request->user(), $scope, (string) $request->validated('policy_version'));
        } else {
            $this->consents->revoke(
                $request->user(),
                $scope,
                DataAction::tryFrom((string) $request->validated('data_action')),
            );
        }

        return $this->index($request);
    }

    /** Histórico das decisões (prova de autorização, visível à própria pessoa). */
    public function history(Request $request): JsonResponse
    {
        $events = ConsentEvent::ownedBy($request->user())->latest('created_at')->limit(200)->get();

        return response()->json(['data' => $events->map(fn (ConsentEvent $e) => [
            'scope' => $e->scope->value,
            'action' => $e->action,
            'policy_version' => $e->policy_version,
            'data_action' => $e->data_action?->value,
            'created_at' => $e->created_at->toIso8601String(),
        ])]);
    }
}
