<?php

use App\Modules\Consent\Http\Controllers\ConsentController;
use App\Modules\Conversations\Http\Controllers\ConversationController;
use App\Modules\Conversations\Http\Controllers\NexoPreferenceController;
use App\Modules\Devices\Http\Controllers\DeviceController;
use App\Modules\Devices\Http\Controllers\DeviceSessionController;
use App\Modules\Goals\Http\Controllers\GoalController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\ProfileController;
use App\Modules\Memory\Http\Controllers\MemoryController;
use App\Modules\Memory\Http\Controllers\MemorySuggestionController;
use App\Modules\Nexo\Http\Controllers\NexoController;
use App\Modules\Privacy\Http\Controllers\DataExportController;
use App\Modules\Routine\Http\Controllers\RoutineBlockController;
use Illuminate\Support\Facades\Route;

/*
| Rotas de usuário: sessão do dashboard (Sanctum SPA).
| Rotas de dispositivo: token Sanctum emitido para o Device.
| O middleware actor:* impede que uma credencial acesse rotas da outra.
| db.user restringe a conexão às linhas do usuário nas tabelas com RLS (ADR-0008).
*/

Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum', 'actor:user', 'db.user'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::patch('me', [ProfileController::class, 'update']);
    Route::put('me/email', [ProfileController::class, 'updateEmail'])->middleware('throttle:auth');
    Route::put('me/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:auth');
    Route::get('timezones', [ProfileController::class, 'timezones']);
    Route::delete('me', [AuthController::class, 'destroy'])->middleware('throttle:auth');
    Route::post('me/exports', [DataExportController::class, 'store'])->middleware('throttle:auth');
    Route::get('me/exports/{export}', [DataExportController::class, 'show']);
    Route::get('me/exports/{export}/download', [DataExportController::class, 'download']);

    Route::apiResource('devices', DeviceController::class);
    Route::post('devices/{device}/token', [DeviceController::class, 'reissueToken'])->middleware('throttle:auth');
    Route::apiResource('routine-blocks', RoutineBlockController::class)
        ->parameters(['routine-blocks' => 'routineBlock']);
    Route::apiResource('goals', GoalController::class);

    Route::get('nexo/status', [NexoController::class, 'status']);
    Route::post('nexo/messages', [NexoController::class, 'message'])->middleware('throttle:nexo');

    Route::get('nexo/consents', [ConsentController::class, 'index']);
    Route::get('nexo/consents/history', [ConsentController::class, 'history']);
    Route::put('nexo/consents/{scope}', [ConsentController::class, 'update'])
        ->where('scope', 'nexo\.[a-z.]+');

    Route::get('nexo/preferences', [NexoPreferenceController::class, 'show']);
    Route::put('nexo/preferences', [NexoPreferenceController::class, 'update']);
    Route::get('nexo/preferences/retention-preview', [NexoPreferenceController::class, 'preview']);

    Route::get('nexo/conversations', [ConversationController::class, 'index']);
    Route::delete('nexo/conversations', [ConversationController::class, 'destroyAll']);
    Route::get('nexo/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::patch('nexo/conversations/{conversation}', [ConversationController::class, 'update']);
    Route::delete('nexo/conversations/{conversation}', [ConversationController::class, 'destroy']);
    Route::delete('nexo/conversations/{conversation}/messages/{message}', [ConversationController::class, 'destroyMessage'])
        ->scopeBindings();

    Route::get('nexo/memory-suggestions', [MemorySuggestionController::class, 'index']);
    Route::post('nexo/memory-suggestions/{suggestion}/accept', [MemorySuggestionController::class, 'accept']);
    Route::delete('nexo/memory-suggestions/{suggestion}', [MemorySuggestionController::class, 'reject']);

    Route::delete('nexo/memories', [MemoryController::class, 'destroyAll']);
    Route::post('nexo/memories/{memory}/review', [MemoryController::class, 'review']);
    Route::apiResource('nexo/memories', MemoryController::class)->parameters(['memories' => 'memory']);
});

Route::prefix('device')->middleware(['auth:sanctum', 'actor:device', 'abilities:device:sync'])->group(function () {
    Route::get('me', [DeviceSessionController::class, 'me']);
    Route::post('token/rotate', [DeviceSessionController::class, 'rotateToken']);
});
