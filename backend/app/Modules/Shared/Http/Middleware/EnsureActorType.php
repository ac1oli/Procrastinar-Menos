<?php

namespace App\Modules\Shared\Http\Middleware;

use App\Models\User;
use App\Modules\Devices\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante o tipo de quem está autenticado.
 *
 * O Sanctum resolve $request->user() para o dono do token, que pode ser um
 * User (dashboard) ou um Device (agentes). Sem esta verificação, um token de
 * dispositivo conseguiria chamar rotas de usuário e vice-versa.
 *
 * Uso: ->middleware('actor:user') ou ->middleware('actor:device').
 */
class EnsureActorType
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $actor = $request->user();

        $allowed = match ($type) {
            'user' => $actor instanceof User,
            'device' => $actor instanceof Device && ! $actor->isRevoked(),
            default => false,
        };

        abort_unless($allowed, Response::HTTP_FORBIDDEN, 'Credencial não permitida para este recurso.');

        return $next($request);
    }
}
