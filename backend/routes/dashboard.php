<?php

use App\Modules\Web\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard React na mesma origem da API (ADR-0010).
|
| Registrado FORA do grupo "web" (bootstrap/app.php): sem sessão, sem cookies e sem
| CSRF. A visita à interface não cria linha em `sessions`; a sessão só nasce em
| /sanctum/csrf-cookie, quando o app precisa dela.
|
| Qualquer caminho da interface devolve o index.html da versão publicada. Ficam de fora:
| - prefixos do backend: api, sanctum, up, app (arquivos estáticos) e storage;
| - caminhos com extensão de arquivo (um .js ausente responde 404, nunca HTML).
*/
Route::get('/{path?}', DashboardController::class)
    ->where('path', '(?!(?:api|sanctum|up|app|storage)(?:/|$))(?!.*\.[A-Za-z0-9]{1,8}$).*')
    ->name('dashboard');
