<?php

use App\Modules\Shared\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de conversas com o NEXO (ADR-0008 §2.1, §2.6).
 *
 * - Só recebe dados com o consentimento nexo.history, fora de conversas privadas.
 * - O conteúdo chega já mascarado (D5). Busca por palavras via tsvector (D6).
 * - FK composta (conversation_id, user_id): o banco impede mensagem de uma
 *   conta numa conversa de outra, mesmo com bug na aplicação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexo_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            // null = até a pessoa excluir.
            $table->unsignedSmallInteger('history_retention_days')->nullable();
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->timestamp('last_message_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'user_id']);
            $table->index(['user_id', 'last_message_at']);
            $table->index('expires_at');
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->uuid('user_id');
            $table->string('role', 16);
            $table->text('content');
            $table->unsignedSmallInteger('redaction_count')->default(0);
            $table->string('model', 64)->nullable();
            $table->string('prompt_version', 64)->nullable();
            $table->timestamp('created_at');

            $table->foreign(['conversation_id', 'user_id'])
                ->references(['id', 'user_id'])->on('conversations')
                ->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['id', 'user_id']);
            $table->index(['conversation_id', 'created_at']);
        });

        DB::statement("ALTER TABLE conversation_messages ADD COLUMN content_tsv tsvector GENERATED ALWAYS AS (to_tsvector('portuguese', content)) STORED");
        DB::statement('CREATE INDEX conversation_messages_content_tsv_index ON conversation_messages USING gin (content_tsv)');
        DB::statement("ALTER TABLE conversation_messages ADD CONSTRAINT conversation_messages_role_check CHECK (role IN ('user', 'assistant'))");

        RowLevelSecurity::enable('nexo_preferences');
        RowLevelSecurity::enable('conversations');
        RowLevelSecurity::enable('conversation_messages');
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('nexo_preferences');
    }
};
