<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
