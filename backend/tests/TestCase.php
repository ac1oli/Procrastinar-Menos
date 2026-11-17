<?php

namespace Tests;

use App\Models\User;
use App\Modules\Shared\Database\DatabaseUserScope;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Nenhum teste fala com o ai-service/Ollama reais sem querer.
        Http::preventStrayRequests();
    }

    /**
     * Executa consultas diretas com a conexão restrita a um usuário (RLS).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function asDatabaseUser(User|string $user, Closure $callback): mixed
    {
        return DatabaseUserScope::run($user, $callback);
    }

    /** Simula o dashboard (origem de primeira parte) para autenticação por sessão. */
    protected function fromDashboard(): static
    {
        return $this->withHeader('Referer', 'http://localhost');
    }

    /**
     * Autentica as próximas requisições com um token de dispositivo.
     * Os guards são esquecidos para não reaproveitar um usuário de Sanctum::actingAs().
     */
    protected function asDevice(string $plainTextToken): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($plainTextToken);
    }
}
