<?php

use App\Modules\Shared\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Diário de exclusões e exportações (ADR-0008 §2.8, D9).
 *
 * deletion_journal: só identificadores, sem conteúdo, preenchido por gatilho
 * em toda exclusão (inclusive em cascata), para que uma restauração de backup
 * reaplique o que a pessoa apagou depois do backup. A aplicação só pode
 * inserir e expurgar entradas antigas (função restrita); não pode ler nem
 * alterar o diário.
 */
return new class extends Migration
{
    private const JOURNALED = ['users', 'conversations', 'conversation_messages', 'memories', 'memory_suggestions'];

    public function up(): void
    {
        $role = (string) config('database.runtime_role');

        Schema::create('deletion_journal', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('entity_type', 32);
            $table->uuid('entity_id');
            $table->uuid('user_id')->nullable();
            $table->timestamp('deleted_at')->useCurrent();

            $table->index('deleted_at');
        });

        DB::statement("REVOKE ALL ON deletion_journal FROM {$role}");
        DB::statement("GRANT INSERT ON deletion_journal TO {$role}");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_deletion() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              -- users não tem user_id; um IF (e não CASE) evita avaliar OLD.user_id nessa tabela.
              IF TG_TABLE_NAME = 'users' THEN
                INSERT INTO deletion_journal (entity_type, entity_id, user_id) VALUES (TG_TABLE_NAME, OLD.id, OLD.id);
              ELSE
                INSERT INTO deletion_journal (entity_type, entity_id, user_id) VALUES (TG_TABLE_NAME, OLD.id, OLD.user_id);
              END IF;
              RETURN OLD;
            END $$
        SQL);

        foreach (self::JOURNALED as $table) {
            DB::statement("CREATE TRIGGER {$table}_journal_deletion AFTER DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION journal_deletion()");
        }

        // Expurgo com prazo mínimo garantido, executável pela aplicação sem ler o diário.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION purge_deletion_journal(older_than_days integer) RETURNS integer
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
            DECLARE purged integer;
            BEGIN
              IF older_than_days < 35 THEN
                RAISE EXCEPTION 'o diário de exclusões deve ser mantido por pelo menos 35 dias';
              END IF;
              DELETE FROM deletion_journal WHERE deleted_at < now() - make_interval(days => older_than_days);
              GET DIAGNOSTICS purged = ROW_COUNT;
              RETURN purged;
            END $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION purge_deletion_journal(integer) FROM PUBLIC');
        DB::statement("GRANT EXECUTE ON FUNCTION purge_deletion_journal(integer) TO {$role}");

        Schema::create('data_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('path')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        RowLevelSecurity::enable('data_exports');
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
        foreach (self::JOURNALED as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_journal_deletion ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS purge_deletion_journal(integer)');
        DB::statement('DROP FUNCTION IF EXISTS journal_deletion()');
        Schema::dropIfExists('deletion_journal');
    }
};
