<?php

use App\Modules\Shared\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quais memórias embasaram cada resposta guardada ("o NEXO considerou…").
 *
 * Derivado puro: some com a mensagem ou com a memória (cascata). Só existe
 * para respostas guardadas no histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_memory_usages', function (Blueprint $table) {
            $table->uuid('message_id');
            $table->uuid('memory_id');
            $table->uuid('user_id');
            $table->float('score')->nullable();

            $table->primary(['message_id', 'memory_id']);
            $table->foreign(['message_id', 'user_id'])->references(['id', 'user_id'])->on('conversation_messages')->cascadeOnDelete();
            $table->foreign(['memory_id', 'user_id'])->references(['id', 'user_id'])->on('memories')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('memory_id');
        });

        RowLevelSecurity::enable('message_memory_usages');
    }

    public function down(): void
    {
        Schema::dropIfExists('message_memory_usages');
    }
};
