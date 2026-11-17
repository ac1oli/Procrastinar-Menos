<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase as BaseRefreshDatabase;

/**
 * RefreshDatabase que recria o banco de testes como dono das tabelas.
 *
 * Os testes em si rodam com o papel de execução (sujeito à RLS), como a
 * aplicação; só o migrate:fresh usa a conexão pgsql_owner (ADR-0008, D8).
 */
trait RefreshDatabase
{
    use BaseRefreshDatabase;

    protected function migrateFreshUsing()
    {
        return [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--database' => 'pgsql_owner',
        ];
    }
}
