<#
.SYNOPSIS
    Recria o banco isolado do teste de contrato do dashboard e sobe o backend-e2e.

.DESCRIPTION
    Só toca no banco procrastinar_menos_e2e (dados fictícios descartáveis). Nunca
    acessa o banco principal. Cria o banco como dono das tabelas, roda as migrations
    e sobe o container pm-backend-e2e (perfil "e2e", porta 127.0.0.1:8010).

.EXAMPLE
    pwsh infrastructure/scripts/e2e-db.ps1
    pwsh infrastructure/scripts/e2e-db.ps1 -Down   # para o container e apaga o banco e2e
#>
param([switch]$Down)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\read-env.ps1')

$envFile = Join-Path $PSScriptRoot '..\docker\.env'
$compose = Join-Path $PSScriptRoot '..\docker\compose.yml'
$envValues = Read-DotEnv $envFile
$owner = $envValues['POSTGRES_USER']
$database = 'procrastinar_menos_e2e'   # fixo: este script não aceita outro nome

function Psql([string]$Sql) {
    $Sql | docker exec -i pm-postgres psql -X -q -v ON_ERROR_STOP=1 -U $owner -d postgres | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'psql falhou' }
}

docker compose -f $compose --env-file $envFile --profile e2e stop backend-e2e 2>$null | Out-Null
Psql "DROP DATABASE IF EXISTS $database WITH (FORCE)"
if ($Down) {
    docker compose -f $compose --env-file $envFile --profile e2e rm -f backend-e2e 2>$null | Out-Null
    Write-Host 'Banco e backend de E2E removidos.' -ForegroundColor Green
    exit 0
}

Psql "CREATE DATABASE $database OWNER $owner"
$env:DB_OWNER_USERNAME = $owner
$env:DB_OWNER_PASSWORD = $envValues['POSTGRES_PASSWORD']
try {
    docker exec -e DB_DATABASE=$database -e DB_OWNER_USERNAME -e DB_OWNER_PASSWORD pm-backend php artisan migrate --database=pgsql_owner --force | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'migrations falharam' }
} finally {
    Remove-Item Env:DB_OWNER_USERNAME, Env:DB_OWNER_PASSWORD -ErrorAction SilentlyContinue
}

docker compose -f $compose --env-file $envFile --profile e2e up -d backend-e2e 2>&1 | Out-Null
Write-Host "Backend de E2E em http://127.0.0.1:8010 (banco $database)." -ForegroundColor Green
