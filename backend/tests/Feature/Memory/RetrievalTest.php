<?php

namespace Tests\Feature\Memory;

use App\Models\User;
use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Goals\Models\Goal;
use App\Modules\Memory\MemoryService;
use App\Modules\Memory\Models\Memory;
use App\Modules\Memory\Retrieval\MemoryRetriever;
use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Data\EmbeddingPurpose;
use App\Modules\Nexo\Exceptions\AiFailure;
use App\Modules\Routine\Models\RoutineBlock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshDatabase;
use Tests\Fakes\FakeNexoGateway;
use Tests\TestCase;

/** Recuperação contextual e contexto autorizado (ADR-0008 §2.5, R12). Dados fictícios. */
class RetrievalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FakeNexoGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Pessoa Fictícia', 'email' => 'ficticia@example.com']);
        $this->gateway = new FakeNexoGateway;
        $this->app->instance(NexoGateway::class, $this->gateway);
        Sanctum::actingAs($this->user);
    }

    public function test_without_memory_consent_nothing_is_retrieved_or_embedded(): void
    {
        $this->grant(ConsentScope::Memory);
        $this->remember('Prefere estudar Python de manhã.');
        $this->putJson('/api/nexo/consents/nexo.memory', ['granted' => false, 'data_action' => 'keep'])->assertOk();
        $this->gateway->embeddings = [];

        $this->ask('Quando devo estudar Python?')->assertJsonPath('data.memories_used', []);

        $this->assertSame([], $this->lastContext()->memories);
        $this->assertSame([], $this->gateway->embeddings);
    }

    public function test_relevant_memory_is_retrieved_and_unrelated_is_not(): void
    {
        $this->grant(ConsentScope::Memory);
        $study = $this->remember('Prefere estudar Python de manhã.');
        $this->remember('Gosta de jogar futebol aos domingos.');

        $used = $this->ask('Quando devo estudar Python?')->json('data.memories_used');

        $this->assertSame([$study->id], array_column($used, 'id'));
        $this->assertSame([['kind' => 'preferencia', 'content' => 'Prefere estudar Python de manhã.']], $this->lastContext()->memories);
        $this->assertSame(EmbeddingPurpose::Query, end($this->gateway->embeddings)['purpose']);
    }

    public function test_pinned_memories_always_enter_and_archived_never(): void
    {
        $this->grant(ConsentScope::Memory);
        $this->remember('Gosta de ser chamada de Fic.', ['pinned' => true]);
        $archived = $this->remember('Prefere estudar Python de manhã.');
        $this->asUser(fn () => $archived->update(['status' => 'archived']));

        $contents = array_column($this->ask('Quando devo estudar Python?')->json('data.memories_used'), 'content');

        $this->assertSame(['Gosta de ser chamada de Fic.'], $contents);
    }

    public function test_sensitive_memories_need_consent_and_strong_similarity(): void
    {
        $this->grant(ConsentScope::Memory);
        $this->grant(ConsentScope::MemorySensitive);
        $this->remember('Tem TDAH e rende mais com tarefas curtas.');

        $this->assertCount(1, $this->ask('TDAH: tarefas curtas rendem mais?')->json('data.memories_used'));
        // Coincidência de uma palavra não basta para dado sensível.
        $this->assertCount(0, $this->ask('Quais tarefas tenho hoje?')->json('data.memories_used'));

        $this->putJson('/api/nexo/consents/nexo.memory.sensitive', ['granted' => false, 'data_action' => 'keep'])->assertOk();
        $this->assertCount(0, $this->ask('TDAH: tarefas curtas rendem mais?')->json('data.memories_used'));
    }

    public function test_identical_memory_of_another_account_is_never_retrieved(): void
    {
        $other = User::factory()->create();
        foreach ([$this->user, $other] as $user) {
            $this->asDatabaseUser($user, function () use ($user) {
                app(ConsentService::class)->grant($user, ConsentScope::Memory, config('nexo.memory_policy_version'));
                app(MemoryService::class)->create($user, ['kind' => 'preferencia', 'content' => 'Prefere estudar Python de manhã.']);
            });
        }

        $retrieved = $this->asDatabaseUser($this->user, fn () => app(MemoryRetriever::class)->retrieve($this->user, 'estudar Python'));

        $this->assertCount(1, $retrieved);
        $this->assertSame($this->user->id, $retrieved[0]->memory->user_id);
    }

    public function test_falls_back_to_text_search_when_embedding_fails(): void
    {
        $this->grant(ConsentScope::Memory);
        $this->remember('Prefere estudar Python de manhã.');
        $this->gateway->embedFailure = AiFailure::Timeout;

        $used = $this->ask('Python ou JavaScript?')->assertOk()->json('data.memories_used');

        $this->assertSame(['Prefere estudar Python de manhã.'], array_column($used, 'content'));
    }

    public function test_stale_embedding_is_ignored(): void
    {
        $this->grant(ConsentScope::Memory);
        $memory = $this->remember('Prefere estudar Python de manhã.');
        // Conteúdo muda sem gerar novo vetor (IA fora do ar).
        $this->gateway->embedFailure = AiFailure::Unavailable;
        $this->patchJson("/api/nexo/memories/{$memory->id}", ['content' => 'Gosta de cozinhar aos sábados.'])->assertOk();
        $this->gateway->embedFailure = null;

        // O vetor antigo ("estudar Python") não pode trazer a memória nova.
        $this->assertCount(0, $this->ask('Quando estudar Python?')->json('data.memories_used'));
    }

    public function test_context_respects_quantity_and_character_budget(): void
    {
        $this->grant(ConsentScope::Memory);
        for ($i = 0; $i < 12; $i++) {
            $this->remember("Estudo Python item {$i} ".str_repeat('detalhe ', 50));
        }

        $memories = $this->ask('estudo python')->json('data.memories_used');

        $this->assertLessThanOrEqual(config('nexo.memory.retrieval.max_results'), count($memories));
        $this->assertLessThanOrEqual(config('nexo.memory.retrieval.budget_chars'), array_sum(array_map('mb_strlen', array_column($memories, 'content'))));
    }

    public function test_usage_is_recorded_and_deleted_with_message_or_memory(): void
    {
        $this->grant(ConsentScope::Memory);
        $this->grant(ConsentScope::History);
        $memory = $this->remember('Prefere estudar Python de manhã.');

        $conversation = $this->ask('Quando devo estudar Python?')->json('data.conversation_id');

        $this->asUser(function () use ($memory) {
            $this->assertNotNull($memory->refresh()->last_used_at);
            $this->assertSame(1, DB::table('message_memory_usages')->count());
        });

        $this->deleteJson("/api/nexo/conversations/{$conversation}")->assertNoContent();
        $this->asUser(fn () => $this->assertSame(0, DB::table('message_memory_usages')->count()));

        $this->ask('Quando devo estudar Python?');
        $this->deleteJson("/api/nexo/memories/{$memory->id}")->assertNoContent();
        $this->asUser(fn () => $this->assertSame(0, DB::table('message_memory_usages')->count()));
    }

    public function test_routine_and_goals_only_with_their_own_consent(): void
    {
        $today = CarbonImmutable::now('America/Sao_Paulo')->dayOfWeekIso;
        RoutineBlock::factory()->for($this->user)->create(['title' => 'Estudo de Python', 'days_of_week' => [$today], 'start_time' => '07:00', 'end_time' => '08:00', 'category' => 'study', 'notes' => 'nota privada']);
        RoutineBlock::factory()->for($this->user)->create(['title' => 'Outro dia', 'days_of_week' => [$today % 7 + 1]]);
        Goal::factory()->for($this->user)->create(['title' => 'Aprender Laravel', 'type' => 'learning', 'description' => 'descrição privada']);
        Goal::factory()->for($this->user)->create(['title' => 'Pausado', 'status' => 'paused']);

        $this->ask('Como está meu dia?');
        $this->assertNull($this->lastContext()->profile);

        $this->grant(ConsentScope::ContextRoutine);
        $this->ask('Como está meu dia?');
        $this->assertSame(['routine_today' => ['07:00–08:00 Estudo de Python (study)'], 'goals' => []], $this->lastContext()->profile);

        $this->grant(ConsentScope::ContextGoals);
        $this->ask('Como está meu dia?');
        $this->assertSame(['Aprender Laravel (learning)'], $this->lastContext()->profile['goals']);

        $sent = json_encode($this->lastContext()->toArray());
        foreach (['nota privada', 'descrição privada', 'Pessoa Fictícia', 'ficticia@example.com', 'Outro dia', 'Pausado'] as $absent) {
            $this->assertStringNotContainsString($absent, $sent);
        }
    }

    private function ask(string $message)
    {
        return $this->postJson('/api/nexo/messages', ['message' => $message])->assertOk();
    }

    private function lastContext()
    {
        return end($this->gateway->replies)['context'];
    }

    private function remember(string $content, array $extra = []): Memory
    {
        return $this->asUser(fn () => app(MemoryService::class)->create($this->user, ['kind' => 'preferencia', 'content' => $content, ...$extra]));
    }

    private function grant(ConsentScope $scope): void
    {
        $this->asUser(fn () => app(ConsentService::class)->grant($this->user, $scope, config('nexo.memory_policy_version')));
    }

    private function asUser(\Closure $callback): mixed
    {
        return $this->asDatabaseUser($this->user, $callback);
    }
}
