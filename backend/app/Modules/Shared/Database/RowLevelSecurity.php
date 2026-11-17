<?php

namespace App\Modules\Shared\Database;

use Illuminate\Support\Facades\DB;

/**
 * Row-Level Security para tabelas com `user_id` (ADR-0008, D8).
 *
 * A política só deixa ler e gravar linhas do usuário definido por
 * DatabaseUserScope. FORCE estende a regra ao dono da tabela; a aplicação
 * usa um papel sem BYPASSRLS, então não há caminho de contorno pela conexão
 * da aplicação.
 */
final class RowLevelSecurity
{
    public static function enable(string $table): void
    {
        self::assertIdentifier($table);

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(
            "CREATE POLICY {$table}_owner_only ON {$table} "
            .'USING (user_id = app_current_user_id()) '
            .'WITH CHECK (user_id = app_current_user_id())'
        );
    }

    private static function assertIdentifier(string $table): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $table) !== 1) {
            throw new \InvalidArgumentException("Nome de tabela inválido: {$table}");
        }
    }
}
