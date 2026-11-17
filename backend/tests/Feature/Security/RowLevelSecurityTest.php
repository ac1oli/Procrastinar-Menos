<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Consent\Models\Consent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Isolamento no banco (ADR-0008, D8): a conexão da aplicação não pode
 * contornar a RLS, e cada conexão só enxerga as linhas do usuário definido.
 */
class RowLevelSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** Tabelas do NEXO: todas com RLS ligada e forçada. */
    public const PROTECTED_TABLES = [
        'consents',
        'consent_events',
        'nexo_preferences',
        'conversations',
        'conversation_messages',
    ];

    public function test_application_connection_cannot_bypass_rls(): void
    {
        $role = DB::selectOne('select rolname, rolsuper, rolbypassrls from pg_roles where rolname = current_user');

        $this->assertSame(config('database.runtime_role'), $role->rolname);
        $this->assertFalse($role->rolsuper, 'A aplicação não pode conectar como superusuário.');
        $this->assertFalse($role->rolbypassrls, 'A aplicação não pode ter BYPASSRLS.');

        $owned = DB::scalar("select count(*) from pg_tables where schemaname = 'public' and tableowner = current_user");
        $this->assertSame(0, $owned, 'A aplicação não pode ser dona de tabelas.');
    }

    public function test_application_connection_has_no_ddl_nor_migrations_access(): void
    {
        $this->assertQueryFails('create table rls_probe (id int)');
        $this->assertQueryFails('select * from migrations');
        $this->assertQueryFails('alter table consents disable row level security');
    }

    public function test_every_protected_table_has_forced_rls_and_user_policy(): void
    {
        foreach (self::PROTECTED_TABLES as $table) {
            $flags = DB::selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );
            $this->assertNotNull($flags, "Tabela {$table} não existe.");
            $this->assertTrue($flags->relrowsecurity, "RLS desligada em {$table}.");
            $this->assertTrue($flags->relforcerowsecurity, "RLS não forçada em {$table}.");

            $policies = DB::scalar('select count(*) from pg_policies where tablename = ?', [$table]);
            $this->assertSame(1, $policies, "Política ausente em {$table}.");
        }
    }

    public function test_rows_are_invisible_without_a_user_scope(): void
    {
        $user = User::factory()->create();
        $this->asDatabaseUser($user, fn () => $this->consentFor($user));

        $this->assertSame(0, Consent::count());
        $this->assertSame(1, $this->asDatabaseUser($user, fn () => Consent::count()));
    }

    public function test_scope_only_sees_own_rows_even_without_application_filter(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();
        $this->asDatabaseUser($alice, fn () => $this->consentFor($alice));
        $this->asDatabaseUser($bob, fn () => $this->consentFor($bob));

        // Consulta sem ownedBy(): quem filtra é o banco.
        $visible = $this->asDatabaseUser($alice, fn () => Consent::query()->pluck('user_id')->all());

        $this->assertSame([$alice->id], $visible);
    }

    public function test_cannot_write_rows_for_another_user(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();

        $this->asDatabaseUser($alice, function () use ($alice, $bob) {
            $this->assertQueryFails(fn () => $this->consentFor($bob));

            $own = $this->consentFor($alice);
            $this->assertQueryFails(fn () => DB::table('consents')->where('id', $own->id)->update(['user_id' => $bob->id]));
        });

        $this->assertSame(0, $this->asDatabaseUser($bob, fn () => Consent::count()));
    }

    private function consentFor(User $user): Consent
    {
        return Consent::create(['user_id' => $user->id, 'scope' => 'nexo.memory', 'granted' => true]);
    }

    private function assertQueryFails(string|\Closure $query): void
    {
        try {
            DB::transaction(fn () => is_string($query) ? DB::statement($query) : $query());
        } catch (QueryException $e) {
            $this->assertContains($e->errorInfo[0] ?? '', ['42501', '42P01'], $e->getMessage());

            return;
        }

        $this->fail('A consulta deveria ter sido recusada pelo banco.');
    }
}
