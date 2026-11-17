<#
.SYNOPSIS
    Verifica se o ambiente de desenvolvimento do Procrastinar Menos está pronto.

.DESCRIPTION
    Somente leitura: não instala nem altera nada. Reporta ferramentas,
    versões, Docker, PostgreSQL/pgvector e Ollama (incluindo um teste real
    de geração quando -TestModel é informado).

.EXAMPLE
    pwsh infrastructure/scripts/check-env.ps1
    pwsh infrastructure/scripts/check-env.ps1 -TestModel qwen3:8b
#>
param(
    [string]$TestModel,
    [string]$OllamaUrl = 'http://127.0.0.1:11434'
)

$ErrorActionPreference = 'Continue'
$failures = 0

function Write-Check([string]$Name, [bool]$Ok, [string]$Detail) {
    $mark = if ($Ok) { '[ OK ]' } else { '[FALHA]' }
    $color = if ($Ok) { 'Green' } else { 'Yellow' }
    Write-Host ("{0} {1,-22} {2}" -f $mark, $Name, $Detail) -ForegroundColor $color
    if (-not $Ok) { $script:failures++ }
}

function Test-Tool([string]$Name, [string]$Cmd, [string[]]$VersionArgs = @('--version')) {
    $c = Get-Command $Cmd -ErrorAction SilentlyContinue
    if (-not $c) { Write-Check $Name $false 'não encontrado no PATH'; return }
    $v = (& $Cmd @VersionArgs 2>&1 | Select-Object -First 1) -as [string]
    Write-Check $Name $true $v.Trim()
}

Write-Host "`n== Ferramentas ==" -ForegroundColor Cyan
Test-Tool 'Git'      'git'
Test-Tool 'Node.js'  'node'
Test-Tool 'pnpm'     'pnpm'
Test-Tool 'Python'   'python'
Test-Tool 'Docker'   'docker'
Test-Tool 'Ollama'   'ollama'
Test-Tool '.NET SDK' 'dotnet'

Write-Host "`n== WSL2 / Docker ==" -ForegroundColor Cyan
$wsl = (wsl --status 2>&1 | Out-String) -replace "`0", ''
Write-Check 'WSL' ($LASTEXITCODE -eq 0) (($wsl -split "`n" | Where-Object { $_.Trim() } | Select-Object -First 1) -as [string]).Trim()

if (Get-Command docker -ErrorAction SilentlyContinue) {
    docker info --format '{{.ServerVersion}}' 2>$null | Out-Null
    Write-Check 'Docker engine' ($LASTEXITCODE -eq 0) $(if ($LASTEXITCODE -eq 0) { 'respondendo' } else { 'Docker Desktop não está em execução' })

    $pg = docker inspect -f '{{.State.Health.Status}}' pm-postgres 2>$null
    Write-Check 'PostgreSQL (pm-postgres)' ($pg -eq 'healthy') $(if ($pg) { $pg } else { 'container não existe' })
    if ($pg -eq 'healthy') {
        $ext = docker exec pm-postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "select extversion from pg_extension where extname=''vector''"' 2>$null
        Write-Check 'pgvector' ([bool]$ext) $(if ($ext) { "versão $ext" } else { 'extensão não instalada' })
    }
}

Write-Host "`n== Ollama ==" -ForegroundColor Cyan
try {
    $ver = Invoke-RestMethod "$OllamaUrl/api/version" -TimeoutSec 5
    Write-Check 'Servidor Ollama' $true "v$($ver.version) em $OllamaUrl"
    $tags = Invoke-RestMethod "$OllamaUrl/api/tags" -TimeoutSec 5
    Write-Check 'Modelos locais' ($tags.models.Count -gt 0) (($tags.models | ForEach-Object name) -join ', ')
} catch {
    Write-Check 'Servidor Ollama' $false "sem resposta em $OllamaUrl"
}

if ($TestModel) {
    try {
        $body = @{ model = $TestModel; prompt = 'Responda apenas: OK'; stream = $false; think = $false } | ConvertTo-Json
        $r = Invoke-RestMethod "$OllamaUrl/api/generate" -Method Post -Body $body -ContentType 'application/json' -TimeoutSec 300
        $tps = if ($r.eval_duration) { [math]::Round($r.eval_count / ($r.eval_duration / 1e9), 1) } else { 0 }
        Write-Check "Geração ($TestModel)" ([bool]$r.response) "$tps tokens/s — resposta: $($r.response.Trim())"
    } catch {
        Write-Check "Geração ($TestModel)" $false $_.Exception.Message
    }
}

Write-Host ''
if ($failures -eq 0) { Write-Host 'Ambiente OK.' -ForegroundColor Green; exit 0 }
Write-Host "$failures verificação(ões) com falha." -ForegroundColor Yellow
exit 1
