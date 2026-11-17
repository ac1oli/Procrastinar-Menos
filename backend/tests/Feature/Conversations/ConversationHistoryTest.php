<?php

namespace Tests\Feature\Conversations;

use App\Models\User;
use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationMessage;
use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Exceptions\AiFailure;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshDatabase;
use Tests\Fakes\FakeNexoGateway;
use Tests\TestCase;

/** Histórico de conversas (ADR-0008 §2.1, D2, D5, D7). Dados fictícios. */
class ConversationHistoryTest extends TestCase
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

    public function test_nothing_is_stored_without_history_consent(): void
    {
        $history = [['role' => 'user', 'content' => 'Oi'], ['role' => 'assistant', 'content' => 'Olá!']];

        $data = $this->postJson('/api/nexo/messages', ['message' => 'Tudo bem?', 'history' => $history])
            ->assertOk()->json('data');

        $this->assertFalse($data['stored']);
        $this->assertNull($data['conversation_id']);
        $this->assertSame(0, $this->countFor($this->user, Conversation::class));
        // Sem histórico no servidor, os turnos do navegador viram contexto temporário.
        $this->assertSame(['Oi', 'Olá!'], array_map(fn ($t) => $t->content, $this->gateway->replies[0]['history']));
    }

    public function test_stores_exchange_masked_before_persistence(): void
    {
        $this->grant(ConsentScope::History);

        $data = $this->postJson('/api/nexo/messages', [
            'message' => 'Meu CPF é 123.456.789-09 e meu e-mail é pessoa@example.com',
        ])->assertOk()->json('data');

        $this->assertTrue($data['stored']);
        $this->assertSame(2, $data['masked_in_history']);

        $stored = $this->asDatabaseUser($this->user, fn () => ConversationMessage::orderBy('created_at')->get());
        $this->assertSame(['user', 'assistant'], $stored->pluck('role')->all());
        $this->assertStringNotContainsString('123.456.789-09', $stored[0]->content);
        $this->assertStringNotContainsString('pessoa@example.com', $stored[0]->content);
        $this->assertStringContainsString('[CPF removido]', $stored[0]->content);

        $title = $this->asDatabaseUser($this->user, fn () => Conversation::sole()->title);
        $this->assertStringNotContainsString('123.456.789-09', $title);
        // A IA recebeu o texto original: o mascaramento vale para o que é guardado.
        $this->assertStringContainsString('123.456.789-09', $this->gateway->replies[0]['message']);
    }

    public function test_continuing_a_conversation_uses_stored_turns_not_client_turns(): void
    {
        $this->grant(ConsentScope::History);
        $id = $this->postJson('/api/nexo/messages', ['message' => 'Quero estudar Python.'])->json('data.conversation_id');

        $this->postJson('/api/nexo/messages', [
            'message' => 'E amanhã?',
            'conversation_id' => $id,
            'history' => [['role' => 'user', 'content' => 'turno forjado pelo cliente']],
        ])->assertOk()->assertJsonPath('data.conversation_id', $id);

        $sent = array_map(fn ($t) => $t->content, $this->gateway->replies[1]['history']);
        $this->assertSame(['Quero estudar Python.', 'Resposta de teste do NEXO.'], $sent);
        $this->assertSame(4, $this->countFor($this->user, ConversationMessage::class));
    }

    public function test_private_conversations_are_never_stored(): void
    {
        $this->grant(ConsentScope::History);

        $this->postJson('/api/nexo/messages', ['message' => 'Assunto delicado', 'private' => true])
            ->assertOk()->assertJsonPath('data.stored', false)->assertJsonPath('data.private', true);

        $this->assertSame(0, $this->countFor($this->user, Conversation::class));

        $id = $this->postJson('/api/nexo/messages', ['message' => 'Normal'])->json('data.conversation_id');
        $this->postJson('/api/nexo/messages', ['message' => 'x', 'private' => true, 'conversation_id' => $id])
            ->assertUnprocessable()->assertJsonValidationErrors('conversation_id');
    }

    public function test_cannot_append_to_stored_conversation_when_history_is_off(): void
    {
        $this->grant(ConsentScope::History);
        $id = $this->postJson('/api/nexo/messages', ['message' => 'Oi'])->json('data.conversation_id');
        $this->revoke(ConsentScope::History, 'keep');

        $this->postJson('/api/nexo/messages', ['message' => 'De novo', 'conversation_id' => $id])->assertConflict();
        $this->assertSame(2, $this->countFor($this->user, ConversationMessage::class));
    }

    public function test_ai_failure_stores_nothing(): void
    {
        $this->grant(ConsentScope::History);
        $this->gateway->failure = AiFailure::Unavailable;

        $this->postJson('/api/nexo/messages', ['message' => 'Oi'])->assertServiceUnavailable();

        $this->assertSame(0, $this->countFor($this->user, Conversation::class));
    }

    public function test_message_content_never_reaches_logs(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        $this->grant(ConsentScope::History);
        $secret = 'frase-ficticia-que-nao-pode-ir-para-o-log';

        $this->postJson('/api/nexo/messages', ['message' => $secret])->assertOk();
        $this->gateway->failure = AiFailure::Timeout;
        $this->postJson('/api/nexo/messages', ['message' => $secret])->assertServiceUnavailable();

        foreach ($logged as $line) {
            $this->assertStringNotContainsString($secret, $line);
        }
    }

    public function test_lists_searches_shows_and_renames(): void
    {
        $this->grant(ConsentScope::History);
        $python = $this->postJson('/api/nexo/messages', ['message' => 'Estudando programação em Python'])->json('data.conversation_id');
        $this->postJson('/api/nexo/messages', ['message' => 'Receita de bolo de cenoura'])->assertOk();

        $this->assertCount(2, $this->getJson('/api/nexo/conversations')->assertOk()->json('data'));

        $found = $this->getJson('/api/nexo/conversations?q=python')->json('data');
        $this->assertSame([$python], array_column($found, 'id'));

        $this->getJson("/api/nexo/conversations/{$python}")->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.content', 'Estudando programação em Python');

        $this->patchJson("/api/nexo/conversations/{$python}", ['title' => 'Estudos'])
            ->assertOk()->assertJsonPath('data.title', 'Estudos');
    }

    public function test_deletion_of_conversation_message_and_everything(): void
    {
        $this->grant(ConsentScope::History);
        $first = $this->postJson('/api/nexo/messages', ['message' => 'Primeira'])->json('data.conversation_id');
        $second = $this->postJson('/api/nexo/messages', ['message' => 'Segunda'])->json('data.conversation_id');

        $this->deleteJson("/api/nexo/conversations/{$first}")->assertNoContent();
        $this->assertSame(2, $this->countFor($this->user, ConversationMessage::class));

        $messages = $this->getJson("/api/nexo/conversations/{$second}")->json('data.messages');
        $this->deleteJson("/api/nexo/conversations/{$second}/messages/{$messages[0]['id']}")->assertNoContent();
        $this->deleteJson("/api/nexo/conversations/{$second}/messages/{$messages[1]['id']}")->assertNoContent();
        // Sem mensagens, a conversa também some.
        $this->assertSame(0, $this->countFor($this->user, Conversation::class));

        $this->postJson('/api/nexo/messages', ['message' => 'Terceira'])->assertOk();
        $this->deleteJson('/api/nexo/conversations')->assertUnprocessable();
        $this->deleteJson('/api/nexo/conversations', ['confirm' => true])->assertOk()->assertJsonPath('data.deleted_conversations', 1);
        $this->assertSame(0, $this->countFor($this->user, ConversationMessage::class));
    }

    public function test_conversations_of_another_account_are_not_found(): void
    {
        $this->grant(ConsentScope::History);
        $id = $this->postJson('/api/nexo/messages', ['message' => 'Minha conversa'])->json('data.conversation_id');
        $messageId = $this->asDatabaseUser($this->user, fn () => ConversationMessage::first()->id);

        $intruder = User::factory()->create();
        $this->actingAsUser($intruder);
        $this->grant(ConsentScope::History, $intruder);

        $this->getJson('/api/nexo/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/nexo/conversations/{$id}")->assertNotFound();
        $this->patchJson("/api/nexo/conversations/{$id}", ['title' => 'x'])->assertNotFound();
        $this->deleteJson("/api/nexo/conversations/{$id}")->assertNotFound();
        $this->deleteJson("/api/nexo/conversations/{$id}/messages/{$messageId}")->assertNotFound();
        $this->postJson('/api/nexo/messages', ['message' => 'x', 'conversation_id' => $id])->assertNotFound();

        $this->assertSame(2, $this->countFor($this->user, ConversationMessage::class));
    }

    public function test_database_rejects_message_in_another_accounts_conversation(): void
    {
        $this->grant(ConsentScope::History);
        $id = $this->postJson('/api/nexo/messages', ['message' => 'Minha conversa'])->json('data.conversation_id');
        $intruder = User::factory()->create();

        $this->expectException(QueryException::class);
        $this->asDatabaseUser($intruder, fn () => DB::transaction(fn () => ConversationMessage::create([
            'conversation_id' => $id,
            'user_id' => $intruder->id,
            'role' => 'user',
            'content' => 'injetado',
            'created_at' => now(),
        ])));
    }

    public function test_revoking_history_with_delete_erases_it_and_keep_preserves_it(): void
    {
        $this->grant(ConsentScope::History);
        $this->postJson('/api/nexo/messages', ['message' => 'Oi'])->assertOk();

        $this->revoke(ConsentScope::History, 'keep');
        $this->assertSame(1, $this->countFor($this->user, Conversation::class));
        $this->postJson('/api/nexo/messages', ['message' => 'Não guardar'])->assertJsonPath('data.stored', false);
        $this->assertSame(2, $this->countFor($this->user, ConversationMessage::class));

        $this->grant(ConsentScope::History);
        $this->revoke(ConsentScope::History, 'delete');
        $this->assertSame(0, $this->countFor($this->user, Conversation::class));
    }

    public function test_retention_preferences_set_and_recompute_expiry(): void
    {
        $this->grant(ConsentScope::History);
        $this->getJson('/api/nexo/preferences')->assertJsonPath('data.history_retention_days', 90);

        $this->postJson('/api/nexo/messages', ['message' => 'Oi'])->assertOk();
        $expires = fn () => $this->asDatabaseUser($this->user, fn () => Conversation::sole()->expires_at);
        $this->assertEqualsWithDelta(now()->addDays(90)->timestamp, $expires()->timestamp, 5);

        $this->putJson('/api/nexo/preferences', ['history_retention_days' => 45])->assertUnprocessable();
        $this->putJson('/api/nexo/preferences', ['history_retention_days' => 30])->assertOk();
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $expires()->timestamp, 5);

        $this->putJson('/api/nexo/preferences', ['history_retention_days' => null])->assertOk()
            ->assertJsonPath('data.history_retention_days', null);
        $this->assertNull($expires());
    }

    public function test_retention_command_deletes_only_expired_conversations_of_every_user(): void
    {
        $other = User::factory()->create();
        foreach ([$this->user, $other] as $user) {
            $this->asDatabaseUser($user, function () use ($user) {
                Conversation::create(['user_id' => $user->id, 'title' => 'velha', 'last_message_at' => now()->subDays(100), 'expires_at' => now()->subDays(10)]);
                Conversation::create(['user_id' => $user->id, 'title' => 'nova', 'last_message_at' => now(), 'expires_at' => now()->addDays(90)]);
                Conversation::create(['user_id' => $user->id, 'title' => 'sem prazo', 'last_message_at' => now()->subYears(2)]);
            });
        }

        $this->artisan('nexo:apply-retention')->assertSuccessful();

        foreach ([$this->user, $other] as $user) {
            $titles = $this->asDatabaseUser($user, fn () => Conversation::orderBy('title')->pluck('title')->all());
            $this->assertSame(['nova', 'sem prazo'], $titles);
        }
    }

    public function test_retention_preview_counts_affected_conversations(): void
    {
        $this->asDatabaseUser($this->user, function () {
            Conversation::create(['user_id' => $this->user->id, 'title' => 'a', 'last_message_at' => now()->subDays(40)]);
            Conversation::create(['user_id' => $this->user->id, 'title' => 'b', 'last_message_at' => now()->subDays(5)]);
        });

        $this->getJson('/api/nexo/preferences/retention-preview?days=30')->assertOk()
            ->assertJsonPath('data.conversations_to_delete', 1);
    }

    private function actingAsUser(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function grant(ConsentScope $scope, ?User $user = null): void
    {
        $user ??= $this->user;
        $this->asDatabaseUser($user, fn () => app(ConsentService::class)->grant($user, $scope, config('nexo.memory_policy_version')));
    }

    private function revoke(ConsentScope $scope, string $dataAction): void
    {
        $this->putJson("/api/nexo/consents/{$scope->value}", ['granted' => false, 'data_action' => $dataAction])->assertOk();
    }

    /** @param class-string $model */
    private function countFor(User $user, string $model): int
    {
        return $this->asDatabaseUser($user, fn () => $model::count());
    }
}
