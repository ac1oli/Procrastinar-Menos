<?php

use App\Modules\Devices\Http\Controllers\DeviceController;
use App\Modules\Devices\Http\Controllers\DeviceSessionController;
use App\Modules\Goals\Http\Controllers\GoalController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Nexo\Http\Controllers\NexoController;
use App\Modules\Routine\Http\Controllers\RoutineBlockController;
use Illuminate\Support\Facades\Route;

/*
| Rotas de usuário: sessão do dashboard (Sanctum SPA).
| Rotas de dispositivo: token Sanctum emitido para o Device.
| O middleware actor:* impede que uma credencial acesse rotas da outra.
*/

Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum', 'actor:user'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::delete('me', [AuthController::class, 'destroy'])->middleware('throttle:auth');

    Route::apiResource('devices', DeviceController::class);
    Route::post('devices/{device}/token', [DeviceController::class, 'reissueToken'])->middleware('throttle:auth');
    Route::apiResource('routine-blocks', RoutineBlockController::class)
        ->parameters(['routine-blocks' => 'routineBlock']);
    Route::apiResource('goals', GoalController::class);

    Route::get('nexo/status', [NexoController::class, 'status']);
    Route::post('nexo/messages', [NexoController::class, 'message'])->middleware('throttle:nexo');
});

Route::prefix('device')->middleware(['auth:sanctum', 'actor:device', 'abilities:device:sync'])->group(function () {
    Route::get('me', [DeviceSessionController::class, 'me']);
    Route::post('token/rotate', [DeviceSessionController::class, 'rotateToken']);
});
