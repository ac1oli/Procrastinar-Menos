<#
.SYNOPSIS
    Recria o banco isolado dos testes de contrato/E2E do dashboard e sobe os backends de E2E.

.DESCRIPTION
    Só toca no banco procrastinar_menos_e2e (dados fictícios descartáveis). Nunca
    acessa o banco principal. Cria o banco como dono das tabelas, roda as migrations
    e sobe os containers do perfil "e2e": pm-backend-e2e (127.0.0.1:8010) e
    pm-backend-e2e-sem-ia (127.0.0.1:8011, IA apontada para porta fechada).

.EXAMPLE
    pwsh infrastructure/scripts/e2e-db.ps1
    pwsh infrastructure/scripts/e2e-db.ps1 -Down   # para os containers e apaga o banco e2e
#>
param([switch]$Down)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\read-env.ps1')

$envFile = Join-Path $PSScriptRoot '..\docker\.env'
$compose = Join-Path $PSScriptRoot '..\docker\compose.yml'
$envValues = Read-DotEnv $envFile
$owner = $envValues['POSTGRES_USER']
$database = 'procrastinar_menos_e2e'   # fixo: este script não aceita outro nome
$services = @('backend-e2e', 'backend-e2e-sem-ia')   # só os serviços do perfil e2e

function Psql([string]$Sql) {
    $Sql | docker exec -i pm-postgres psql -X -q -v ON_ERROR_STOP=1 -U $owner -d postgres | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'psql falhou' }
}

docker compose -f $compose --env-file $envFile --profile e2e stop @services 2>$null | Out-Null
Psql "DROP DATABASE IF EXISTS $database WITH (FORCE)"
if ($Down) {
    docker compose -f $compose --env-file $envFile --profile e2e rm -f @services 2>$null | Out-Null
    Write-Host 'Banco e backends de E2E removidos.' -ForegroundColor Green
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

docker compose -f $compose --env-file $envFile --profile e2e up -d --force-recreate --no-deps @services 2>&1 | Out-Null
Write-Host "Backends de E2E em http://127.0.0.1:8010 e :8011 (sem IA), banco $database." -ForegroundColor Green
