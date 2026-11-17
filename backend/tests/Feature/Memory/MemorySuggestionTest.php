<?php

namespace Tests\Feature\Memory;

use App\Models\User;
use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Memory\MemoryService;
use App\Modules\Memory\Models\Memory;
use App\Modules\Memory\Models\MemoryEmbedding;
use App\Modules\Memory\Models\MemorySuggestion;
use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Data\SuggestedMemory;
use App\Modules\Nexo\Exceptions\AiFailure;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshDatabase;
use Tests\Fakes\FakeNexoGateway;
use Tests\TestCase;

/** Sugestões do NEXO aguardam confirmação (R4, R5, D10). Dados fictícios. */
class MemorySuggestionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FakeNexoGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->gateway = new FakeNexoGateway(suggestions: [$this->suggestion('Prefere estudar no início da manhã.')]);
        $this->app->instance(NexoGateway::class, $this->gateway);
        $this->actingAsUser($this->user);
    }

    public function test_no_suggestions_without_consent_or_in_private_mode(): void
    {
        $this->grant(ConsentScope::History);
        $this->grant(ConsentScope::Memory);
        $this->ask('Rendo mais estudando cedo.');
        $this->assertSame([], $this->gateway->suggestCalls);

        $this->grant(ConsentScope::MemorySuggestions);
        $this->postJson('/api/nexo/messages', ['message' => 'Rendo mais cedo.', 'private' => true])->assertOk();
        $this->assertSame([], $this->gateway->suggestCalls);
    }

    public function test_suggestion_waits_for_confirmation_and_expires_in_14_days(): void
    {
        $this->enableSuggestions();

        $this->ask('Rendo muito mais estudando cedo.');

        $this->assertSame(0, $this->rows(Memory::class));
        $pending = $this->getJson('/api/nexo/memory-suggestions')->assertOk()->json('data');
        $this->assertCount(1, $pending);
        $this->assertSame('Prefere estudar no início da manhã.', $pending[0]['content']);
        $this->assertNotNull($pending[0]['source']['conversation_id']);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, strtotime($pending[0]['expires_at']), 5);
        // A sugestão é pedida a partir do texto guardado (já mascarado).
        $this->assertSame('Rendo muito mais estudando cedo.', $this->gateway->suggestCalls[0]['user']->content);
    }

    public function test_sensitive_identifying_invalid_or_duplicate_proposals_are_discarded(): void
    {
        $this->enableSuggestions();
        $this->remember('Gosta de café.');
        $this->gateway->suggestions = [
            $this->suggestion('Tem TDAH.', sensitivity: 'sensitive'),
            $this->suggestion('Toma remédio para ansiedade à noite.'),   // modelo disse "normal"
            $this->suggestion('O e-mail dela é pessoa@example.com.'),
            $this->suggestion('Sabe Python.', kind: 'senha'),
            $this->suggestion('Gosta de café.'),
        ];

        $this->ask('Conversa qualquer.');

        $this->assertSame(0, $this->rows(MemorySuggestion::class));
    }

    public function test_pending_suggestions_are_capped(): void
    {
        config(['nexo.memory.max_pending_suggestions' => 1]);
        $this->enableSuggestions();
        $this->gateway->suggestions = [$this->suggestion('Primeira.'), $this->suggestion('Segunda.')];

        $this->ask('Uma.');
        $this->ask('Outra.');

        $this->assertSame(1, $this->rows(MemorySuggestion::class));
    }

    public function test_accepting_creates_confirmed_memory_with_source(): void
    {
        $this->enableSuggestions();
        $this->ask('Rendo muito mais estudando cedo.');
        $id = $this->getJson('/api/nexo/memory-suggestions')->json('data.0.id');

        $memory = $this->postJson("/api/nexo/memory-suggestions/{$id}/accept")->assertCreated()->json('data');

        $this->assertSame('suggestion_confirmed', $memory['origin']);
        $this->assertSame('declarada', $memory['evidence_level']);
        $this->assertSame('alta', $memory['confidence']);
        $this->assertNotNull($memory['source']['conversation_id']);
        $this->assertNotNull($memory['source']['message_id']);
        $this->assertSame('ready', $memory['semantic_index']);
        $this->assertSame(0, $this->rows(MemorySuggestion::class));
        $this->assertSame(1, $this->rows(MemoryEmbedding::class));
    }

    public function test_accepting_with_edit_and_rejecting_sensitive_edits(): void
    {
        $this->enableSuggestions();
        $this->ask('Rendo muito mais estudando cedo.');
        $id = $this->getJson('/api/nexo/memory-suggestions')->json('data.0.id');

        $this->postJson("/api/nexo/memory-suggestions/{$id}/accept", ['content' => 'Estuda cedo por causa da ansiedade.'])
            ->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->assertSame(1, $this->rows(MemorySuggestion::class));

        $this->postJson("/api/nexo/memory-suggestions/{$id}/accept", ['content' => 'Prefere estudar às 6h.'])
            ->assertCreated()->assertJsonPath('data.content', 'Prefere estudar às 6h.');
    }

    public function test_rejecting_deletes_without_trace(): void
    {
        $this->enableSuggestions();
        $this->ask('Rendo muito mais estudando cedo.');
        $id = $this->getJson('/api/nexo/memory-suggestions')->json('data.0.id');

        $this->deleteJson("/api/nexo/memory-suggestions/{$id}")->assertNoContent();

        $this->assertSame(0, $this->rows(MemorySuggestion::class));
        $this->assertSame(0, $this->rows(Memory::class));
    }

    public function test_expired_suggestions_are_hidden_and_purged(): void
    {
        $this->enableSuggestions();
        $this->ask('Rendo muito mais estudando cedo.');
        $this->asUser(fn () => MemorySuggestion::query()->update(['expires_at' => now()->subMinute()]));

        $this->getJson('/api/nexo/memory-suggestions')->assertJsonCount(0, 'data');
        $this->artisan('nexo:apply-retention')->assertSuccessful();
        $this->assertSame(0, $this->rows(MemorySuggestion::class));
    }

    public function test_deleting_source_conversation_deletes_pending_suggestions(): void
    {
        $this->enableSuggestions();
        $conversation = $this->ask('Rendo muito mais estudando cedo.')->json('data.conversation_id');

        $this->deleteJson("/api/nexo/conversations/{$conversation}")->assertNoContent();

        $this->assertSame(0, $this->rows(MemorySuggestion::class));
    }

    public function test_revoking_suggestions_or_memory_purges_pending(): void
    {
        $this->enableSuggestions();
        $this->ask('Rendo muito mais estudando cedo.');

        $this->putJson('/api/nexo/consents/nexo.memory.suggestions', ['granted' => false])->assertOk();
        $this->assertSame(0, $this->rows(MemorySuggestion::class));

        $this->grant(ConsentScope::MemorySuggestions);
        $this->ask('De novo.');
        $this->putJson('/api/nexo/consents/nexo.history', ['granted' => false, 'data_action' => 'keep'])->assertOk();
        $this->assertSame(0, $this->rows(MemorySuggestion::class));
    }

    public function test_ai_failure_during_suggestion_does_not_affect_reply(): void
    {
        $this->enableSuggestions();
        $this->gateway->suggestFailure = AiFailure::Timeout;

        $this->ask('Rendo muito mais estudando cedo.')->assertJsonPath('data.stored', true);
        $this->assertCount(1, $this->gateway->suggestCalls);
        $this->assertSame(0, $this->rows(MemorySuggestion::class));
    }

    public function test_suggestions_of_another_account_are_not_found(): void
    {
        $this->enableSuggestions();
        $this->ask('Rendo muito mais estudando cedo.');
        $id = $this->getJson('/api/nexo/memory-suggestions')->json('data.0.id');

        $intruder = User::factory()->create();
        $this->actingAsUser($intruder);
        $this->asDatabaseUser($intruder, fn () => app(ConsentService::class)->grant($intruder, ConsentScope::Memory, config('nexo.memory_policy_version')));

        $this->getJson('/api/nexo/memory-suggestions')->assertJsonCount(0, 'data');
        $this->postJson("/api/nexo/memory-suggestions/{$id}/accept")->assertNotFound();
        $this->deleteJson("/api/nexo/memory-suggestions/{$id}")->assertNotFound();
        $this->assertSame(1, $this->rows(MemorySuggestion::class));
    }

    private function enableSuggestions(): void
    {
        foreach ([ConsentScope::History, ConsentScope::Memory, ConsentScope::MemorySuggestions] as $scope) {
            $this->grant($scope);
        }
    }

    private function suggestion(string $content, string $kind = 'preferencia', string $sensitivity = 'normal'): SuggestedMemory
    {
        return new SuggestedMemory($kind, $content, 'Você mencionou.', $sensitivity);
    }

    private function ask(string $message)
    {
        return $this->postJson('/api/nexo/messages', ['message' => $message])->assertOk();
    }

    private function remember(string $content): void
    {
        $this->asUser(fn () => app(MemoryService::class)->create($this->user, ['kind' => 'preferencia', 'content' => $content]));
    }

    private function actingAsUser(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function grant(ConsentScope $scope): void
    {
        $this->asUser(fn () => app(ConsentService::class)->grant($this->user, $scope, config('nexo.memory_policy_version')));
    }

    private function asUser(\Closure $callback): mixed
    {
        return $this->asDatabaseUser($this->user, $callback);
    }

    /** @param class-string $model */
    private function rows(string $model): int
    {
        return $this->asUser(fn () => $model::count());
    }
}
