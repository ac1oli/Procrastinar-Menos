<#
.SYNOPSIS
    Cria ou atualiza o papel de execução da aplicação no PostgreSQL (ADR-0008, D8).

.DESCRIPTION
    A aplicação (backend, agendador, fila) conecta com POSTGRES_RUNTIME_USER: um papel
    sem superusuário, sem BYPASSRLS e que não é dono das tabelas, para que as políticas
    de Row-Level Security sejam efetivamente aplicadas. O dono das tabelas
    (POSTGRES_USER) fica restrito a migrations e backups.

    Idempotente. Não apaga dados. A senha é lida do .env e enviada ao psql pela
    entrada padrão (não aparece em argumentos de processo nem na saída).
    As permissões nas tabelas são concedidas pela migration de privilégios.

.EXAMPLE
    pwsh infrastructure/scripts/provision-db-roles.ps1
#>
param(
    [string]$EnvFile = (Join-Path $PSScriptRoot '..\docker\.env'),
    [string]$Container = 'pm-postgres'
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\read-env.ps1')

$envValues = Read-DotEnv $EnvFile
$owner = $envValues['POSTGRES_USER']
$role = $envValues['POSTGRES_RUNTIME_USER']
$password = $envValues['POSTGRES_RUNTIME_PASSWORD']

if ($role -notmatch '^[a-z_][a-z0-9_]{0,62}$') { throw 'POSTGRES_RUNTIME_USER inválido (use letras minúsculas, dígitos e _).' }
if ($role -eq $owner) { throw 'POSTGRES_RUNTIME_USER deve ser diferente de POSTGRES_USER.' }
if ($password -notmatch '^[A-Za-z0-9_-]{32,}$') { throw 'POSTGRES_RUNTIME_PASSWORD deve ter 32+ caracteres de A-Z a-z 0-9 _ -.' }

$sql = @"
DO `$`$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '$role') THEN
    CREATE ROLE $role LOGIN;
  END IF;
END `$`$;
ALTER ROLE $role WITH LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '$password';
SELECT rolname || ': superuser=' || rolsuper || ' bypassrls=' || rolbypassrls FROM pg_roles WHERE rolname = '$role';
"@

$sql | docker exec -i $Container psql -X -q -t -A -v ON_ERROR_STOP=1 -U $owner -d postgres
if ($LASTEXITCODE -ne 0) { throw "Falha ao provisionar o papel $role." }
Write-Host "Papel $role pronto. Rode as migrations com infrastructure/scripts/artisan-owner.ps1 migrate." -ForegroundColor Green
