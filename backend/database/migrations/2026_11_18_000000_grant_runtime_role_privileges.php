<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Privilégios do papel de execução (ADR-0008, D8).
 *
 * A aplicação conecta com um papel sem superusuário, sem BYPASSRLS e que não
 * é dono das tabelas. Ele recebe só DML (inclusive nas tabelas futuras, via
 * DEFAULT PRIVILEGES); nunca DDL nem acesso à tabela de migrations.
 *
 * Também cria app_current_user_id(), usada pelas políticas de RLS: lê o
 * usuário definido por DatabaseUserScope na conexão; sem ele, devolve NULL e
 * nenhuma linha protegida fica visível.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = $this->runtimeRole();

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_current_user_id() RETURNS uuid
            LANGUAGE sql STABLE
            AS $$ SELECT NULLIF(current_setting('app.user_id', true), '')::uuid $$
        SQL);

        DB::statement("GRANT USAGE ON SCHEMA public TO {$role}");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$role}");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$role}");
        DB::statement("REVOKE ALL ON TABLE migrations FROM {$role}");
        DB::statement("REVOKE ALL ON SEQUENCE migrations_id_seq FROM {$role}");
    }

    public function down(): void
    {
        $role = $this->runtimeRole();

        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM {$role}");
        DB::statement("REVOKE ALL ON ALL TABLES IN SCHEMA public FROM {$role}");
        DB::statement("REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM {$role}");
        DB::statement('DROP FUNCTION IF EXISTS app_current_user_id()');
    }

    private function runtimeRole(): string
    {
        $role = (string) config('database.runtime_role');

        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new RuntimeException('database.runtime_role inválido; defina DB_RUNTIME_ROLE.');
        }
        if (DB::scalar('select current_user') === $role) {
            throw new RuntimeException('Migrations devem rodar como dono das tabelas: use infrastructure/scripts/artisan-owner.ps1 migrate.');
        }
        if (! DB::scalar('select exists(select 1 from pg_roles where rolname = ?)', [$role])) {
            throw new RuntimeException("Papel {$role} não existe: rode infrastructure/scripts/provision-db-roles.ps1.");
        }

        return $role;
    }
};
