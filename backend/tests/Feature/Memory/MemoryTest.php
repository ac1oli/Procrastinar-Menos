<?php

namespace Tests\Feature\Memory;

use App\Models\User;
use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Memory\Enums\MemoryStatus;
use App\Modules\Memory\Models\EmbeddingModel;
use App\Modules\Memory\Models\Memory;
use App\Modules\Memory\Models\MemoryEmbedding;
use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Data\EmbeddingPurpose;
use App\Modules\Nexo\Exceptions\AiFailure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshDatabase;
use Tests\Fakes\FakeNexoGateway;
use Tests\TestCase;

/** Memória de longo prazo (ADR-0008 §2.3, §2.4; D3, D4, D7). Dados fictícios. */
class MemoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FakeNexoGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->gateway = new FakeNexoGateway;
        $this->app->instance(NexoGateway::class, $this->gateway);
        $this->actingAsUser($this->user);
    }

    public function test_creating_requires_memory_consent(): void
    {
        $this->postJson('/api/nexo/memories', $this->memory())->assertConflict();

        $this->grant(ConsentScope::Memory);
        $this->postJson('/api/nexo/memories', $this->memory())->assertCreated();
    }

    public function test_created_memory_has_origin_evidence_confidence_and_embedding(): void
    {
        $this->grant(ConsentScope::Memory);

        $data = $this->postJson('/api/nexo/memories', $this->memory())->assertCreated()->json('data');

        $this->assertSame('user_created', $data['origin']);
        $this->assertSame('declarada', $data['evidence_level']);
        $this->assertSame('alta', $data['confidence']);
        $this->assertSame('active', $data['status']);
        $this->assertSame('normal', $data['sensitivity']);
        $this->assertNotNull($data['created_at']);
        $this->assertNotNull($data['confirmed_at']);
        $this->assertNotNull($data['review_due_at']);
        $this->assertSame('ready', $data['semantic_index']);

        $embedding = $this->asDatabaseUser($this->user, fn () => MemoryEmbedding::sole());
        $this->assertSame($this->user->id, $embedding->user_id);
        $this->assertSame(Memory::hashContent('Prefere estudar no início da manhã.'), $embedding->content_hash);
        $this->assertSame(EmbeddingPurpose::Document, $this->gateway->embeddings[0]['purpose']);
        $this->assertSame(config('nexo.embedding_model'), EmbeddingModel::sole()->name);
    }

    public function test_identifiers_are_always_rejected(): void
    {
        $this->grant(ConsentScope::Memory);

        foreach (['Meu CPF é 123.456.789-09', 'Senha do curso: hunter2', 'Contato pessoa@example.com'] as $content) {
            $this->postJson('/api/nexo/memories', $this->memory(['content' => $content]))
                ->assertUnprocessable()->assertJsonValidationErrors('content');
        }
        $this->assertSame(0, $this->rows(Memory::class));
    }

    public function test_sensitive_memories_require_their_own_consent(): void
    {
        $this->grant(ConsentScope::Memory);

        $this->postJson('/api/nexo/memories', $this->memory(['content' => 'Tenho TDAH e prefiro tarefas curtas.']))
            ->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->postJson('/api/nexo/memories', $this->memory(['content' => 'Prefiro tarefas curtas.', 'sensitive' => true]))
            ->assertUnprocessable();

        $this->grant(ConsentScope::MemorySensitive);
        $this->postJson('/api/nexo/memories', $this->memory(['content' => 'Tenho TDAH e prefiro tarefas curtas.']))
            ->assertCreated()->assertJsonPath('data.sensitivity', 'sensitive');
    }

    public function test_short_stems_do_not_flag_ordinary_words(): void
    {
        $this->grant(ConsentScope::Memory);

        foreach (['Gosta de tocar violão à noite.', 'Está numa missão de ler mais.', 'Trabalha com pastoreio de projetos.'] as $content) {
            $this->postJson('/api/nexo/memories', $this->memory(['content' => $content]))
                ->assertCreated()->assertJsonPath('data.sensitivity', 'normal');
        }
    }

    public function test_pinned_memories_are_limited(): void
    {
        $this->grant(ConsentScope::Memory);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/nexo/memories', $this->memory(['content' => "Fixada {$i}", 'pinned' => true]))->assertCreated();
        }
        $this->postJson('/api/nexo/memories', $this->memory(['content' => 'Sexta', 'pinned' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('pinned');
    }

    public function test_editing_replaces_content_and_regenerates_embedding(): void
    {
        $this->grant(ConsentScope::Memory);
        $id = $this->postJson('/api/nexo/memories', $this->memory())->json('data.id');

        $this->patchJson("/api/nexo/memories/{$id}", ['content' => 'Prefere estudar à noite.'])
            ->assertOk()->assertJsonPath('data.content', 'Prefere estudar à noite.')->assertJsonPath('data.semantic_index', 'ready');

        $this->asDatabaseUser($this->user, function () {
            $this->assertSame(Memory::hashContent('Prefere estudar à noite.'), MemoryEmbedding::sole()->content_hash);
            // Nenhuma versão anterior guardada.
            $this->assertSame(0, DB::table('memories')->where('content', 'like', '%manhã%')->count());
        });
    }

    public function test_memory_survives_ai_outage_and_is_indexed_later(): void
    {
        $this->grant(ConsentScope::Memory);
        $this->gateway->embedFailure = AiFailure::Unavailable;

        $id = $this->postJson('/api/nexo/memories', $this->memory())->assertCreated()
            ->assertJsonPath('data.semantic_index', 'pending')->json('data.id');
        $this->getJson("/api/nexo/memories/{$id}")->assertOk();
        $this->assertSame(0, $this->rows(MemoryEmbedding::class));

        $this->gateway->embedFailure = null;
        $this->artisan('nexo:reindex-memories')->assertSuccessful();

        $this->getJson("/api/nexo/memories/{$id}")->assertJsonPath('data.semantic_index', 'ready');
    }

    public function test_deleting_a_memory_deletes_its_embedding(): void
    {
        $this->grant(ConsentScope::Memory);
        $id = $this->postJson('/api/nexo/memories', $this->memory())->json('data.id');
        $this->assertSame(1, $this->rows(MemoryEmbedding::class));

        $this->deleteJson("/api/nexo/memories/{$id}")->assertNoContent();

        $this->assertSame(0, $this->rows(Memory::class));
        $this->assertSame(0, $this->rows(MemoryEmbedding::class));
    }

    public function test_delete_all_requires_confirmation(): void
    {
        $this->grant(ConsentScope::Memory);
        $this->postJson('/api/nexo/memories', $this->memory())->assertCreated();

        $this->deleteJson('/api/nexo/memories')->assertUnprocessable();
        $this->deleteJson('/api/nexo/memories', ['confirm' => true])->assertOk()->assertJsonPath('data.deleted_memories', 1);
        $this->assertSame(0, $this->rows(MemoryEmbedding::class));
    }

    public function test_archive_review_and_filters(): void
    {
        $this->grant(ConsentScope::Memory);
        $id = $this->postJson('/api/nexo/memories', $this->memory())->json('data.id');

        $this->patchJson("/api/nexo/memories/{$id}", ['status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->patchJson("/api/nexo/memories/{$id}", ['status' => 'needs_review'])->assertUnprocessable();
        $this->assertCount(1, $this->getJson('/api/nexo/memories?status=archived')->json('data'));
        $this->assertCount(1, $this->getJson('/api/nexo/memories?q=estudar')->json('data'));
        $this->assertCount(0, $this->getJson('/api/nexo/memories?q=futebol')->json('data'));

        $this->postJson("/api/nexo/memories/{$id}/review")->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.last_reviewed_at', fn ($v) => $v !== null);
    }

    public function test_stale_memories_are_marked_for_review_not_deleted(): void
    {
        $this->grant(ConsentScope::Memory);
        $this->asDatabaseUser($this->user, function () {
            $this->makeMemory('Antiga sem uso', ['created_at' => now()->subDays(200), 'confirmed_at' => now()->subDays(200)]);
            $this->makeMemory('Antiga usada recentemente', ['created_at' => now()->subDays(200), 'confirmed_at' => now()->subDays(200), 'last_used_at' => now()->subDays(3)]);
            $this->makeMemory('Recente', []);
        });

        $this->artisan('nexo:apply-retention')->assertSuccessful();

        $statuses = $this->asDatabaseUser($this->user, fn () => Memory::pluck('status', 'content')->map->value->all());
        $this->assertSame([
            'Antiga sem uso' => 'needs_review',
            'Antiga usada recentemente' => 'active',
            'Recente' => 'active',
        ], collect($statuses)->sortKeys()->all());
    }

    public function test_memories_of_another_account_are_not_found(): void
    {
        $this->grant(ConsentScope::Memory);
        $id = $this->postJson('/api/nexo/memories', $this->memory())->json('data.id');

        $intruder = User::factory()->create();
        $this->actingAsUser($intruder);

        $this->getJson('/api/nexo/memories')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/nexo/memories/{$id}")->assertNotFound();
        $this->patchJson("/api/nexo/memories/{$id}", ['content' => 'x'])->assertNotFound();
        $this->postJson("/api/nexo/memories/{$id}/review")->assertNotFound();
        $this->deleteJson("/api/nexo/memories/{$id}")->assertNotFound();

        $this->assertSame(0, $this->asDatabaseUser($intruder, fn () => MemoryEmbedding::count()));
        $this->assertSame(1, $this->rows(Memory::class));
    }

    public function test_database_rejects_embedding_owned_by_another_account(): void
    {
        $this->grant(ConsentScope::Memory);
        $id = $this->postJson('/api/nexo/memories', $this->memory())->json('data.id');
        $intruder = User::factory()->create();
        $modelId = EmbeddingModel::sole()->id;

        $this->expectException(QueryException::class);
        $this->asDatabaseUser($intruder, fn () => DB::transaction(fn () => MemoryEmbedding::create([
            'memory_id' => $id, 'user_id' => $intruder->id, 'embedding_model_id' => $modelId,
            'content_hash' => str_repeat('0', 64), 'embedding' => FakeNexoGateway::vector('x'), 'created_at' => now(),
        ])));
    }

    public function test_revoking_memory_with_delete_or_keep(): void
    {
        $this->grant(ConsentScope::Memory);
        $this->postJson('/api/nexo/memories', $this->memory())->assertCreated();

        $this->putJson('/api/nexo/consents/nexo.memory', ['granted' => false, 'data_action' => 'keep'])->assertOk();
        $this->assertSame(1, $this->rows(Memory::class));
        $this->getJson('/api/nexo/memories')->assertJsonCount(1, 'data'); // continua visível
        $this->postJson('/api/nexo/memories', $this->memory(['content' => 'Nova']))->assertConflict();

        $this->grant(ConsentScope::Memory);
        $this->putJson('/api/nexo/consents/nexo.memory', ['granted' => false, 'data_action' => 'delete'])->assertOk();
        $this->assertSame(0, $this->rows(Memory::class));
        $this->assertSame(0, $this->rows(MemoryEmbedding::class));
    }

    public function test_revoking_sensitive_with_delete_removes_only_sensitive_memories(): void
    {
        $this->grant(ConsentScope::Memory);
        $this->grant(ConsentScope::MemorySensitive);
        $this->postJson('/api/nexo/memories', $this->memory())->assertCreated();
        $this->postJson('/api/nexo/memories', $this->memory(['content' => 'Faz terapia às quintas.']))->assertCreated();

        $this->putJson('/api/nexo/consents/nexo.memory.sensitive', ['granted' => false, 'data_action' => 'delete'])->assertOk();

        $this->assertSame(['Prefere estudar no início da manhã.'], $this->asDatabaseUser($this->user, fn () => Memory::pluck('content')->all()));
    }

    public function test_deleting_source_conversation_keeps_confirmed_memory_unless_asked(): void
    {
        $this->grant(ConsentScope::Memory);
        [$first, $second] = $this->asDatabaseUser($this->user, function () {
            $conversations = [];
            foreach (['Primeira', 'Segunda'] as $title) {
                $conversation = Conversation::create(['user_id' => $this->user->id, 'title' => $title, 'last_message_at' => now()]);
                $this->makeMemory("Memória da {$title}", ['source_conversation_id' => $conversation->id]);
                $conversations[] = $conversation->id;
            }

            return $conversations;
        });

        $this->getJson("/api/nexo/conversations/{$first}")->assertJsonCount(1, 'data.memories_from_this_conversation');
        $this->deleteJson("/api/nexo/conversations/{$first}")->assertNoContent();
        $this->deleteJson("/api/nexo/conversations/{$second}?with_memories=1")->assertNoContent();

        $remaining = $this->asDatabaseUser($this->user, fn () => Memory::get(['content', 'source_conversation_id']));
        $this->assertSame(['Memória da Primeira'], $remaining->pluck('content')->all());
        $this->assertNull($remaining[0]->source_conversation_id);
    }

    private function memory(array $overrides = []): array
    {
        return [...['kind' => 'preferencia', 'content' => 'Prefere estudar no início da manhã.'], ...$overrides];
    }

    private function makeMemory(string $content, array $attributes): Memory
    {
        $memory = Memory::create([
            'user_id' => $this->user->id, 'kind' => 'preferencia', 'content' => $content,
            'content_hash' => Memory::hashContent($content), 'origin' => 'user_created',
            'evidence_level' => 'declarada', 'confidence' => 'alta', 'status' => MemoryStatus::Active,
            ...array_intersect_key($attributes, array_flip(['source_conversation_id', 'last_used_at', 'confirmed_at'])),
        ]);

        if (isset($attributes['created_at'])) {
            $memory->forceFill(['created_at' => $attributes['created_at']])->saveQuietly();
        }

        return $memory;
    }

    private function actingAsUser(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function grant(ConsentScope $scope): void
    {
        $this->asDatabaseUser($this->user, fn () => app(ConsentService::class)->grant($this->user, $scope, config('nexo.memory_policy_version')));
    }

    /** @param class-string $model */
    private function rows(string $model): int
    {
        return $this->asDatabaseUser($this->user, fn () => $model::count());
    }
}
