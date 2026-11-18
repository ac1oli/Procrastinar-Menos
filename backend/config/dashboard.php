<?php

/*
| Dashboard React servido pelo Laravel na mesma origem da API (ADR-0010).
|
| - Os arquivos estáticos com hash ficam em public/app/assets (cache longo).
| - Cada versão publicada fica em storage/app/dashboard/releases/<id>/ (fora da pasta
|   pública); o arquivo storage/app/dashboard/current aponta a versão ativa.
| - Publicação e reversão: infrastructure/scripts/publish-dashboard.ps1.
*/

return [
    'path' => env('DASHBOARD_PATH', storage_path('app/dashboard')),
];
