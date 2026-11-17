<?php

namespace Tests\Feature\Privacy;

use App\Models\User;
use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\ConsentEvent;
use App\Modules\Conversations\Models\Conversation;
use App\Modules\Conversations\Models\ConversationMessage;
use App\Modules\Conversations\Models\NexoPreference;
use App\Modules\Memory\Models\Memory;
use App\Modules\Memory\Models\MemoryEmbedding;
use App\Modules\Memory\Models\MemorySuggestion;
use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Data\SuggestedMemory;
use App\Modules\Privacy\Models\DataExport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshDatabase;
use Tests\Fakes\FakeNexoGateway;
use Tests\TestCase;
use ZipArchive;

/**
 * Exclusão de conta, exportação e diário de exclusões do NEXO
 * (ADR-0008 §2.6, §2.8; R8, R9, R11). Dados fictícios.
 */
class NexoPrivacyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'senhaFicticia123';

    /** Tudo que o NEXO guarda sobre uma pessoa. */
    private const NEXO_MODELS = [
        Consent::class, ConsentEvent::class, NexoPreference::class, Conversation::class, ConversationMessage::class,
        Memory::class, MemoryEmbedding::class, MemorySuggestion::class, DataExport::class,
    ];

    private User $user;

    private FakeNexoGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create(['password' => self::PASSWORD]);
        $this->gateway = new FakeNexoGateway(suggestions: [new SuggestedMemory('preferencia', 'Prefere estudar cedo.', 'Você disse.', 'normal')]);
        $this->app->instance(NexoGateway::class, $this->gateway);
    }

    public function test_account_deletion_erases_every_nexo_record_and_export_file(): void
    {
        $this->populate($this->user);
        $other = User::factory()->create(['password' => self::PASSWORD]);
        $this->populate($other);
        $this->assertGreaterThan(0, $this->rowsFor($this->user, MemoryEmbedding::class));
        $this->assertGreaterThan(0, $this->rowsFor($this->user, MemorySuggestion::class));
        $this->asDatabaseUser($this->user, fn () => $this->assertGreaterThan(0, DB::table('message_memory_usages')->count()));
        $this->assertNotEmpty(Storage::disk('local')->allFiles('exports'));

        $this->signedIn($this->user)->deleteJson('/api/me', ['password' => self::PASSWORD])->assertNoContent();

        foreach (self::NEXO_MODELS as $model) {
            $this->assertSame(0, $this->rowsFor($this->user->id, $model), $model);
            $this->assertGreaterThan(0, $this->rowsFor($other, $model), "{$model} de outra conta não pode ser afetado");
        }
        $this->asDatabaseUser($this->user->id, fn () => $this->assertSame(0, DB::table('message_memory_usages')->count()));
        $this->assertCount(1, Storage::disk('local')->allFiles('exports'), 'só o arquivo da outra conta permanece');
    }

    public function test_export_requires_password_and_is_rate_limited(): void
    {
        $this->signedIn($this->user)->postJson('/api/me/exports')->assertUnprocessable();
        $this->signedIn($this->user)->postJson('/api/me/exports', ['password' => 'errada'])->assertUnprocessable();

        $this->signedIn($this->user)->postJson('/api/me/exports', ['password' => self::PASSWORD])->assertAccepted();
        $this->signedIn($this->user)->postJson('/api/me/exports', ['password' => self::PASSWORD])->assertTooManyRequests();
    }

    public function test_export_contains_all_nexo_data_without_vectors_and_is_single_use(): void
    {
        $this->populate($this->user, export: false);

        $id = $this->signedIn($this->user)->postJson('/api/me/exports', ['password' => self::PASSWORD])
            ->assertAccepted()->json('data.id');
        $this->getJson("/api/me/exports/{$id}")->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonMissingPath('data.path');

        // Cifrado em repouso: o texto das memórias não aparece no arquivo guardado.
        $stored = Storage::disk('local')->get("exports/{$id}.zip.enc");
        $this->assertStringNotContainsString('Prefere estudar Python', $stored);

        $response = $this->get("/api/me/exports/{$id}/download")->assertOk()->assertHeader('Content-Type', 'application/zip');
        $files = $this->unzip($response->getContent());

        $this->assertEqualsCanonicalizing([
            'LEIAME.md', 'nexo/consentimentos.json', 'nexo/preferencias.json', 'nexo/conversas.json',
            'nexo/memorias.json', 'nexo/sugestoes_pendentes.json', 'nexo/memorias.md',
        ], array_keys($files));
        $memories = json_decode($files['nexo/memorias.json'], true);
        $this->assertSame('Prefere estudar Python de manhã.', $memories[0]['content']);
        $this->assertSame('declarada', $memories[0]['evidence_level']);
        foreach (array_filter($files, fn ($name) => str_ends_with($name, '.json'), ARRAY_FILTER_USE_KEY) as $json) {
            $this->assertStringNotContainsString('"embedding', $json);
        }
        $this->assertNotEmpty(json_decode($files['nexo/consentimentos.json'], true)['history']);
        $this->assertStringContainsString('[CPF removido]', $files['nexo/conversas.json']);

        $this->get("/api/me/exports/{$id}/download")->assertGone();
        $this->assertSame([], Storage::disk('local')->allFiles('exports'));
    }

    public function test_exports_of_another_account_are_not_found(): void
    {
        $id = $this->signedIn($this->user)->postJson('/api/me/exports', ['password' => self::PASSWORD])->json('data.id');

        $intruder = User::factory()->create();
        $this->signedIn($intruder)->getJson("/api/me/exports/{$id}")->assertNotFound();
        $this->get("/api/me/exports/{$id}/download")->assertNotFound();
    }

    public function test_expired_exports_are_purged_by_retention(): void
    {
        $id = $this->signedIn($this->user)->postJson('/api/me/exports', ['password' => self::PASSWORD])->json('data.id');
        $this->asDatabaseUser($this->user, fn () => DataExport::query()->update(['expires_at' => now()->subMinute()]));

        $this->get("/api/me/exports/{$id}/download")->assertGone();
        $this->artisan('nexo:apply-retention')->assertSuccessful();

        $this->assertSame([], Storage::disk('local')->allFiles('exports'));
        $this->assertSame(0, $this->rowsFor($this->user, DataExport::class));
    }

    public function test_application_cannot_read_or_tamper_with_the_deletion_journal(): void
    {
        foreach (['select * from deletion_journal', 'update deletion_journal set user_id = null', 'delete from deletion_journal'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("Deveria ser recusado: {$sql}");
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
            }
        }

        // Expurgo só pela função, e nunca abaixo da janela dos backups.
        try {
            DB::transaction(fn () => DB::scalar('select purge_deletion_journal(1)'));
            $this->fail('Expurgo abaixo de 35 dias deveria ser recusado.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('35 dias', $e->getMessage());
        }
        $this->assertSame(0, DB::scalar('select purge_deletion_journal(35)'));
    }

    private function populate(User $user, bool $export = true): void
    {
        $this->asDatabaseUser($user, function () use ($user) {
            foreach ([ConsentScope::History, ConsentScope::Memory, ConsentScope::MemorySuggestions] as $scope) {
                app(ConsentService::class)->grant($user, $scope, config('nexo.memory_policy_version'));
            }
            NexoPreference::create(['user_id' => $user->id, 'history_retention_days' => 30]);
        });

        $this->signedIn($user);
        $this->postJson('/api/nexo/memories', ['kind' => 'preferencia', 'content' => 'Prefere estudar Python de manhã.'])->assertCreated();
        $this->postJson('/api/nexo/messages', ['message' => 'Meu CPF é 123.456.789-09; quando estudo Python?'])->assertOk();

        if ($export) {
            $this->postJson('/api/me/exports', ['password' => self::PASSWORD])->assertAccepted();
        }
    }

    /**
     * Troca de usuário entre requisições. A sessão em memória dos testes é
     * compartilhada; sem limpá-la, o AuthenticateSession do Sanctum compara o
     * hash de senha do usuário anterior e desloga o novo (no navegador real,
     * cada pessoa tem a própria sessão).
     */
    private function signedIn(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->actingAs($user, 'web')->fromDashboard();
    }

    /** @param class-string $model */
    private function rowsFor(User|string $user, string $model): int
    {
        return $this->asDatabaseUser($user, fn () => $model::count());
    }

    /** @return array<string, string> */
    private function unzip(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pm-test-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $zip->open($path);
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $files;
    }
}
