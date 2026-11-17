<?php

use App\Modules\Shared\Http\Middleware\EnsureActorType;
use App\Modules\Shared\Http\Middleware\ScopeDatabaseToUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Modules/Audit/Console',
        __DIR__.'/../app/Modules/Nexo/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->throttleApi();

        $middleware->alias([
            'actor' => EnsureActorType::class,
            'abilities' => CheckAbilities::class,
            'db.user' => ScopeDatabaseToUser::class,
        ]);

        // O escopo da RLS precisa existir antes do route model binding.
        $middleware->prependToPriorityList(SubstituteBindings::class, ScopeDatabaseToUser::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A mensagem de QueryException inclui os valores da consulta (conteúdo de
        // conversas e memórias). O log recebe só o SQL com placeholders (ADR-0008, D5).
        $exceptions->report(function (QueryException $e) {
            Log::error('database.query_failed', [
                'sqlstate' => $e->errorInfo[0] ?? $e->getCode(),
                'sql' => $e->getSql(),
            ]);

            return false;
        });
    })->create();
