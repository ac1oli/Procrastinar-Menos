<?php

namespace App\Modules\Shared\Http\Middleware;

use App\Models\User;
use App\Modules\Shared\Database\DatabaseUserScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe a conexão às linhas do usuário autenticado (RLS, ADR-0008 D8).
 *
 * Roda antes do route model binding (prioridade em bootstrap/app.php), para
 * que um recurso de outra conta nem seja encontrado. O escopo é limpo ao fim
 * da requisição, mesmo com erro.
 */
class ScopeDatabaseToUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        DatabaseUserScope::set($user instanceof User ? $user : null);

        try {
            return $next($request);
        } finally {
            DatabaseUserScope::set(null);
        }
    }
}
