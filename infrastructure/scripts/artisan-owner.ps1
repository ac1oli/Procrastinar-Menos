<#
.SYNOPSIS
    Executa um comando artisan no pm-backend com as credenciais do dono do banco.

.DESCRIPTION
    O backend roda com o papel de execução (sujeito à RLS). Migrations e a suíte de
    testes (que recria o banco de testes) precisam do dono das tabelas. As credenciais
    são repassadas só a este processo, via variáveis de ambiente herdadas por
    `docker exec -e NOME` (sem valor na linha de comando), e nunca ficam no ambiente
    permanente do container.

    `migrate` recebe --database=pgsql_owner automaticamente.

.EXAMPLE
    pwsh infrastructure/scripts/artisan-owner.ps1 migrate
    pwsh infrastructure/scripts/artisan-owner.ps1 test
    pwsh infrastructure/scripts/artisan-owner.ps1 test --filter=Memory
#>
param(
    [Parameter(ValueFromRemainingArguments = $true)][string[]]$ArtisanArgs
)

$ErrorActionPreference = 'Stop'
$EnvFile = Join-Path $PSScriptRoot '..\docker\.env'
$Container = 'pm-backend'
. (Join-Path $PSScriptRoot 'lib\read-env.ps1')

if (-not $ArtisanArgs) { throw 'Informe o comando artisan (ex.: migrate, test).' }

$envValues = Read-DotEnv $EnvFile
$env:DB_OWNER_USERNAME = $envValues['POSTGRES_USER']
$env:DB_OWNER_PASSWORD = $envValues['POSTGRES_PASSWORD']

$command = @($ArtisanArgs)
if ($command[0] -like 'migrate*' -and -not ($command -match '^--database')) {
    $command += '--database=pgsql_owner'
}

try {
    docker exec -e DB_OWNER_USERNAME -e DB_OWNER_PASSWORD $Container php artisan @command
    exit $LASTEXITCODE
} finally {
    Remove-Item Env:DB_OWNER_USERNAME, Env:DB_OWNER_PASSWORD -ErrorAction SilentlyContinue
}
