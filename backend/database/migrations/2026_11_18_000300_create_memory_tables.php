<?php

use App\Modules\Shared\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Memória de longo prazo do NEXO e seus embeddings (ADR-0008 §2.3, §2.4).
 *
 * - Origem, evidência, confiança e datas obrigatórias (R6).
 * - Excluir a conversa de origem só desfaz o vínculo (SET NULL só da coluna
 *   de origem): a memória confirmada tem finalidade própria (R9).
 * - O embedding mora em tabela própria, com o mesmo user_id e ON DELETE
 *   CASCADE pela FK composta: some na mesma transação da memória (R8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embedding_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 128)->unique();
            $table->unsignedSmallInteger('dimensions');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('memories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->text('content');
            $table->char('content_hash', 64);
            $table->string('sensitivity', 16)->default('normal');
            $table->string('origin', 32);
            $table->string('evidence_level', 16);
            $table->string('confidence', 8);
            $table->string('status', 16)->default('active');
            $table->boolean('pinned')->default(false);
            $table->uuid('source_conversation_id')->nullable();
            $table->uuid('source_message_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index('source_conversation_id');
        });

        DB::statement("ALTER TABLE memories ADD COLUMN content_tsv tsvector GENERATED ALWAYS AS (to_tsvector('portuguese', content)) STORED");
        DB::statement('CREATE INDEX memories_content_tsv_index ON memories USING gin (content_tsv)');
        DB::statement(<<<'SQL'
            ALTER TABLE memories
              ADD CONSTRAINT memories_content_length CHECK (char_length(content) BETWEEN 1 AND 500),
              ADD CONSTRAINT memories_kind_check CHECK (kind IN ('preferencia','objetivo_pessoal','conhecimento','padrao','contexto_de_vida','restricao')),
              ADD CONSTRAINT memories_sensitivity_check CHECK (sensitivity IN ('normal','sensitive')),
              ADD CONSTRAINT memories_origin_check CHECK (origin IN ('user_created','suggestion_confirmed','imported','agent_observed')),
              ADD CONSTRAINT memories_evidence_check CHECK (evidence_level IN ('observada','declarada','consultada','praticada','demonstrada')),
              ADD CONSTRAINT memories_confidence_check CHECK (confidence IN ('alta','media','baixa')),
              ADD CONSTRAINT memories_status_check CHECK (status IN ('active','needs_review','archived')),
              ADD CONSTRAINT memories_source_conversation_fk FOREIGN KEY (source_conversation_id, user_id)
                REFERENCES conversations (id, user_id) ON DELETE SET NULL (source_conversation_id),
              ADD CONSTRAINT memories_source_message_fk FOREIGN KEY (source_message_id, user_id)
                REFERENCES conversation_messages (id, user_id) ON DELETE SET NULL (source_message_id)
        SQL);

        Schema::create('memory_embeddings', function (Blueprint $table) {
            $table->uuid('memory_id')->primary();
            $table->uuid('user_id');
            $table->foreignUuid('embedding_model_id')->constrained('embedding_models');
            $table->char('content_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['memory_id', 'user_id'])->references(['id', 'user_id'])->on('memories')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'embedding_model_id']);
        });

        DB::statement('ALTER TABLE memory_embeddings ADD COLUMN embedding vector(1024) NOT NULL');
        DB::statement('CREATE INDEX memory_embeddings_embedding_hnsw ON memory_embeddings USING hnsw (embedding vector_cosine_ops)');

        RowLevelSecurity::enable('memories');
        RowLevelSecurity::enable('memory_embeddings');
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_embeddings');
        Schema::dropIfExists('memories');
        Schema::dropIfExists('embedding_models');
    }
};
