<?php

use App\Modules\Shared\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consentimentos independentes e revogáveis do NEXO (ADR-0008 §2.2).
 *
 * `consents` guarda o estado atual (sem linha = nunca decidido); `consent_events`
 * é o histórico append-only de concessões e revogações, com a versão do texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 64);
            $table->boolean('granted');
            $table->string('policy_version', 64)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'scope']);
        });

        Schema::create('consent_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 64);
            $table->string('action', 16);
            $table->string('policy_version', 64)->nullable();
            $table->string('data_action', 16)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });

        RowLevelSecurity::enable('consents');
        RowLevelSecurity::enable('consent_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_events');
        Schema::dropIfExists('consents');
    }
};
