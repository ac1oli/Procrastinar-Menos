<?php

namespace App\Modules\Shared\Database;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Define o usuário cujas linhas a conexão pode ver nas tabelas com RLS.
 *
 * Requisições HTTP usam o middleware ScopeDatabaseToUser. Jobs e comandos
 * processam um usuário por vez com run(); não existe modo "todos os usuários"
 * pela conexão da aplicação.
 */
final class DatabaseUserScope
{
    public static function set(User|string|null $user): void
    {
        $id = $user instanceof User ? (string) $user->getKey() : (string) $user;

        DB::select("select set_config('app.user_id', ?, false)", [$id]);
    }

    public static function current(): ?string
    {
        $id = DB::scalar("select current_setting('app.user_id', true)");

        return $id === null || $id === '' ? null : (string) $id;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(User|string $user, Closure $callback): mixed
    {
        $previous = self::current();
        self::set($user);

        try {
            return $callback();
        } finally {
            self::set($previous);
        }
    }
}
