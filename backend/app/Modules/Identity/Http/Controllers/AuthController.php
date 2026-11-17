<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Resources\UserResource;
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

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
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
