<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extensões do PostgreSQL usadas pela aplicação. Ficam em migration (e não
 * só no init do container) para que o banco de testes também as tenha.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
    }

    public function down(): void
    {
        // Extensões não são removidas: podem estar em uso por outros objetos.
    }
};
