<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ana Teste',
            'email' => 'Ana@Exemplo.com ',
            'password' => 'senhaSegura123',
            'password_confirmation' => 'senhaSegura123',
        ], $overrides);
    }

    public function test_registers_user_and_starts_session(): void
    {
        $response = $this->fromDashboard()->postJson('/api/auth/register', $this->registrationPayload());

        $response->assertCreated()
            ->assertJsonPath('data.email', 'ana@exemplo.com')
            ->assertJsonPath('data.timezone', 'America/Sao_Paulo')
            ->assertJsonMissingPath('data.password');

        $user = User::firstWhere('email', 'ana@exemplo.com');
        $this->assertTrue(Hash::check('senhaSegura123', $user->password));
        $this->assertTrue(AuditLog::where('user_id', $user->id)->where('action', 'auth.registered')->exists());

        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_rejects_duplicate_email_regardless_of_case(): void
    {
        User::factory()->create(['email' => 'ana@exemplo.com']);

        $this->fromDashboard()
            ->postJson('/api/auth/register', $this->registrationPayload(['email' => 'ANA@exemplo.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_rejects_weak_password(): void
    {
        $this->fromDashboard()
            ->postJson('/api/auth/register', $this->registrationPayload([
                'password' => 'curta',
                'password_confirmation' => 'curta',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_logs_in_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'ana@exemplo.com', 'password' => 'senhaSegura123']);

        $this->fromDashboard()
            ->postJson('/api/auth/login', ['email' => 'Ana@Exemplo.com', 'password' => 'senhaSegura123'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertTrue(AuditLog::where('user_id', $user->id)->where('action', 'auth.login')->exists());
    }

    public function test_rejects_invalid_credentials(): void
    {
        User::factory()->create(['email' => 'ana@exemplo.com', 'password' => 'senhaSegura123']);

        $this->fromDashboard()
            ->postJson('/api/auth/login', ['email' => 'ana@exemplo.com', 'password' => 'errada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_throttles_repeated_login_attempts(): void
    {
        User::factory()->create(['email' => 'ana@exemplo.com', 'password' => 'senhaSegura123']);

        foreach (range(1, 5) as $_) {
            $this->fromDashboard()
                ->postJson('/api/auth/login', ['email' => 'ana@exemplo.com', 'password' => 'errada'])
                ->assertUnprocessable();
        }

        $this->fromDashboard()
            ->postJson('/api/auth/login', ['email' => 'ana@exemplo.com', 'password' => 'senhaSegura123'])
            ->assertTooManyRequests();
    }

    public function test_session_login_requires_first_party_origin(): void
    {
        User::factory()->create(['email' => 'ana@exemplo.com', 'password' => 'senhaSegura123']);

        $this->postJson('/api/auth/login', ['email' => 'ana@exemplo.com', 'password' => 'senhaSegura123'])
            ->assertBadRequest();
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/devices')->assertUnauthorized();
        $this->getJson('/api/routine-blocks')->assertUnauthorized();
        $this->getJson('/api/goals')->assertUnauthorized();
    }

    public function test_logout_ends_session(): void
    {
        User::factory()->create(['email' => 'ana@exemplo.com', 'password' => 'senhaSegura123']);

        $this->fromDashboard()
            ->postJson('/api/auth/login', ['email' => 'ana@exemplo.com', 'password' => 'senhaSegura123'])
            ->assertOk();

        $this->fromDashboard()->postJson('/api/auth/logout')->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->fromDashboard()->getJson('/api/me')->assertUnauthorized();
    }
}
