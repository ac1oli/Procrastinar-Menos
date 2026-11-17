<?php

use App\Modules\Shared\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sugestões de memória aguardando a confirmação da pessoa (ADR-0008 §2.3, D10).
 *
 * Derivado sem finalidade própria: some com a mensagem/conversa de origem
 * (cascata), ao expirar (14 dias), ao ser recusada ou ao virar memória.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_suggestions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('kind', 32);
            $table->string('content', 500);
            $table->string('rationale', 300)->default('');
            $table->uuid('source_conversation_id');
            $table->uuid('source_message_id');
            $table->timestamp('expires_at');
            $table->timestamp('created_at');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign(['source_conversation_id', 'user_id'])->references(['id', 'user_id'])->on('conversations')->cascadeOnDelete();
            $table->foreign(['source_message_id', 'user_id'])->references(['id', 'user_id'])->on('conversation_messages')->cascadeOnDelete();
            $table->index(['user_id', 'expires_at']);
        });

        DB::statement("ALTER TABLE memory_suggestions ADD CONSTRAINT memory_suggestions_kind_check CHECK (kind IN ('preferencia','objetivo_pessoal','conhecimento','padrao','contexto_de_vida','restricao'))");

        RowLevelSecurity::enable('memory_suggestions');
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_suggestions');
    }
};
