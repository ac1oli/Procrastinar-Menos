<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Actions\DeleteAccount;
use App\Modules\Identity\Http\Requests\DeleteAccountRequest;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Autenticação do dashboard via sessão (Sanctum SPA, cookie HttpOnly).
 *
 * Tokens em localStorage não são usados no navegador; dispositivos usam
 * tokens próprios (ver módulo Devices).
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $this->ensureSession($request);

        $user = User::create($request->validated())->refresh();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->audit->record('auth.registered', $user);

        return UserResource::make($user)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): UserResource
    {
        $this->ensureSession($request);

        if (! Auth::guard('web')->attempt($request->validated())) {
            throw ValidationException::withMessages([
                'email' => 'E-mail ou senha incorretos.',
            ]);
        }

        $request->session()->regenerate();
        $user = Auth::guard('web')->user();
        $this->audit->record('auth.login', $user);

        return UserResource::make($user);
    }

    public function logout(Request $request): Response
    {
        $this->audit->record('auth.logout', $request->user());

        $this->endSession($request);

        return response()->noContent();
    }

    /** Exclui a conta e encerra a sessão. Irreversível. */
    public function destroy(DeleteAccountRequest $request, DeleteAccount $deleteAccount): Response
    {
        $user = $request->user();

        // Logout antes da exclusão: o logout pode salvar o remember_token no
        // usuário, o que depois do delete reinseriria o registro.
        $this->endSession($request);

        $deleteAccount($user);

        return response()->noContent();
    }

    /**
     * Encerra a sessão por completo.
     *
     * O driver de sessão em banco grava o user_id lendo Guard::class (singleton
     * `auth.driver` → guard padrão), que nas rotas auth:sanctum é o guard do
     * Sanctum com o usuário em cache. Sem limpar esse cache, a sessão nova
     * seria gravada com o user_id de quem saiu — ou de quem excluiu a conta.
     */
    private function endSession(Request $request): void
    {
        Auth::guard('web')->logout();

        // Instância que o driver de sessão vai consultar (resolvida agora, se ainda não foi).
        $sessionGuard = app(Guard::class);
        if (method_exists($sessionGuard, 'forgetUser')) {
            $sessionGuard->forgetUser();
        }
        // E qualquer outra instância de guard em cache no AuthManager.
        Auth::forgetGuards();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    public function me(Request $request): JsonResponse
    {
        // Status explícito: JsonResource responderia 201 se a instância em
        // memória tivesse acabado de ser criada (ex.: logo após o registro).
        return UserResource::make($request->user())->response()->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Login por sessão só funciona para clientes de primeira parte
     * (origens listadas em SANCTUM_STATEFUL_DOMAINS).
     */
    private function ensureSession(Request $request): void
    {
        abort_unless(
            $request->hasSession(),
            Response::HTTP_BAD_REQUEST,
            'Autenticação por sessão disponível apenas para o dashboard.',
        );
    }
}
