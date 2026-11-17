<?php

namespace Tests\Feature\Consent;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Consent\Models\ConsentEvent;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class ConsentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/nexo/consents')->assertUnauthorized();
    }

    public function test_everything_starts_undecided_and_pending(): void
    {
        $response = $this->getJson('/api/nexo/consents')->assertOk();

        $this->assertSame(config('nexo.memory_policy_version'), $response->json('data.policy_version'));
        $this->assertSame([null], array_values(array_unique(array_column($response->json('data.scopes'), 'granted'))));
        // Atividade dos agentes não está disponível até a FASE 5.
        $this->assertNotContains('nexo.context.activity', $response->json('data.pending'));
        $this->assertCount(6, $response->json('data.pending'));
    }

    public function test_grant_requires_current_policy_version(): void
    {
        $this->grant('nexo.history', 'politica-de-memoria@0.1')->assertUnprocessable()->assertJsonValidationErrors('policy_version');
        $this->putJson('/api/nexo/consents/nexo.history', ['granted' => true])->assertUnprocessable();

        $this->grant('nexo.history')->assertOk();
        $this->assertTrue($this->granted(ConsentScope::History));
    }

    public function test_dependent_scopes_require_their_prerequisites(): void
    {
        $this->grant('nexo.memory.suggestions')->assertUnprocessable();
        $this->grant('nexo.memory.sensitive')->assertUnprocessable();

        $this->grant('nexo.memory')->assertOk();
        $this->grant('nexo.memory.suggestions')->assertUnprocessable(); // falta o histórico
        $this->grant('nexo.history')->assertOk();
        $this->grant('nexo.memory.suggestions')->assertOk();
        $this->grant('nexo.memory.sensitive')->assertOk();
    }

    public function test_unavailable_or_unknown_scopes_are_rejected(): void
    {
        $this->grant('nexo.context.activity')->assertUnprocessable();
        $this->grant('nexo.qualquer.coisa')->assertNotFound();
    }

    public function test_declining_records_decision_without_data_choice(): void
    {
        $this->putJson('/api/nexo/consents/nexo.history', ['granted' => false])->assertOk();

        $response = $this->getJson('/api/nexo/consents');
        $this->assertNotContains('nexo.history', $response->json('data.pending'));
        $this->assertSame('declined', $this->asDatabaseUser($this->user, fn () => ConsentEvent::sole()->action));
    }

    public function test_revoking_scope_with_data_requires_explicit_choice(): void
    {
        $this->grant('nexo.history')->assertOk();

        // O servidor nunca escolhe "excluir" ou "manter" sozinho (D7).
        $this->putJson('/api/nexo/consents/nexo.history', ['granted' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('data_action');
        $this->assertTrue($this->granted(ConsentScope::History));

        $this->putJson('/api/nexo/consents/nexo.history', ['granted' => false, 'data_action' => 'keep'])->assertOk();
        $this->assertFalse($this->granted(ConsentScope::History));
    }

    public function test_revoking_memory_also_revokes_dependents(): void
    {
        foreach (['nexo.memory', 'nexo.history', 'nexo.memory.suggestions', 'nexo.memory.sensitive'] as $scope) {
            $this->grant($scope)->assertOk();
        }

        $this->putJson('/api/nexo/consents/nexo.memory', ['granted' => false, 'data_action' => 'delete'])->assertOk();

        $this->assertFalse($this->granted(ConsentScope::Memory));
        $this->assertFalse($this->granted(ConsentScope::MemorySuggestions));
        $this->assertFalse($this->granted(ConsentScope::MemorySensitive));
        $this->assertTrue($this->granted(ConsentScope::History));
    }

    public function test_history_lists_decisions_with_policy_version(): void
    {
        $this->grant('nexo.goals.invalid')->assertNotFound();
        $this->grant('nexo.context.goals')->assertOk();
        $this->putJson('/api/nexo/consents/nexo.context.goals', ['granted' => false])->assertOk();

        $events = $this->getJson('/api/nexo/consents/history')->assertOk()->json('data');

        $this->assertSame(['revoked', 'granted'], array_column($events, 'action'));
        $this->assertSame(config('nexo.memory_policy_version'), $events[1]['policy_version']);
    }

    public function test_decisions_are_audited_without_content(): void
    {
        $this->grant('nexo.context.routine')->assertOk();

        $log = AuditLog::where('action', 'nexo.consent.granted')->sole();
        $this->assertSame(['scope' => 'nexo.context.routine'], $log->metadata);
    }

    public function test_consents_are_isolated_between_users(): void
    {
        $this->grant('nexo.memory')->assertOk();

        $other = User::factory()->create();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other);

        $scopes = collect($this->getJson('/api/nexo/consents')->json('data.scopes'))->keyBy('scope');
        $this->assertNull($scopes['nexo.memory']['granted']);
        $this->assertSame([], $this->getJson('/api/nexo/consents/history')->json('data'));
        $this->assertFalse($this->asDatabaseUser($other, fn () => app(ConsentService::class)->allows($other, ConsentScope::Memory)));
    }

    private function grant(string $scope, ?string $version = null)
    {
        return $this->putJson("/api/nexo/consents/{$scope}", [
            'granted' => true,
            'policy_version' => $version ?? config('nexo.memory_policy_version'),
        ]);
    }

    private function granted(ConsentScope $scope): bool
    {
        return $this->asDatabaseUser($this->user, fn () => app(ConsentService::class)->allows($this->user, $scope));
    }
}
