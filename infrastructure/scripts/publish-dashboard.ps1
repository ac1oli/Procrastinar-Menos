<#
.SYNOPSIS
    Publica, lista, reverte ou retira a versão do dashboard servida pelo Laravel (ADR-0010).

.DESCRIPTION
    Publicar:
      1. gera o build de produção do dashboard (pnpm build);
      2. recusa o build se encontrar algo com cara de segredo nos arquivos gerados;
      3. guarda a versão inteira em backend/storage/app/dashboard/releases/<id>/ (fora da pasta pública);
      4. copia os arquivos estáticos para backend/public/app/ sem apagar os da versão anterior
         (quem está com a página aberta continua carregando os trechos antigos);
      5. troca o ponteiro backend/storage/app/dashboard/current (troca atômica de arquivo).
    Mantém as últimas -Keep versões e remove arquivos estáticos que nenhuma delas usa.

    Não reinicia containers, não toca no banco e não muda nada fora de backend/public/app e
    backend/storage/app/dashboard.

.EXAMPLE
    pwsh infrastructure/scripts/publish-dashboard.ps1              # build + publicação
    pwsh infrastructure/scripts/publish-dashboard.ps1 -List        # versões guardadas
    pwsh infrastructure/scripts/publish-dashboard.ps1 -Rollback    # volta para a versão anterior
    pwsh infrastructure/scripts/publish-dashboard.ps1 -To <id>     # ativa uma versão guardada
    pwsh infrastructure/scripts/publish-dashboard.ps1 -Unpublish   # tira do ar (a API continua)
#>
[CmdletBinding(DefaultParameterSetName = 'Publish')]
param(
    [Parameter(ParameterSetName = 'Publish')][switch]$SkipBuild,
    [Parameter(ParameterSetName = 'Publish')][ValidateRange(2, 20)][int]$Keep = 3,
    [Parameter(ParameterSetName = 'List')][switch]$List,
    [Parameter(ParameterSetName = 'Rollback')][switch]$Rollback,
    [Parameter(ParameterSetName = 'To')][ValidatePattern('^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$')][string]$To,
    [Parameter(ParameterSetName = 'Unpublish')][switch]$Unpublish
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$dashboard = Join-Path $repo 'dashboard'
$dist = Join-Path $dashboard 'dist'
$root = Join-Path $repo 'backend\storage\app\dashboard'
$releases = Join-Path $root 'releases'
$public = Join-Path $repo 'backend\public\app'
$pointer = Join-Path $root 'current'
$previousPointer = Join-Path $root 'previous'

function Get-Pointer([string]$Path) {
    if (Test-Path -LiteralPath $Path) { return (Get-Content -LiteralPath $Path -Raw).Trim() }
    return $null
}

function Set-Pointer([string]$Path, [string]$Value) {
    $tmp = "$Path.tmp"
    [System.IO.File]::WriteAllText($tmp, "$Value`n")
    Move-Item -LiteralPath $tmp -Destination $Path -Force   # substitui de uma vez
}

function Copy-StaticFiles([string]$ReleaseDir) {
    New-Item -ItemType Directory -Force -Path (Join-Path $public 'assets') | Out-Null
    # Arquivos com hash: só acrescenta (nunca sobrescreve nem apaga os da versão anterior).
    Get-ChildItem -LiteralPath (Join-Path $ReleaseDir 'assets') -File | ForEach-Object {
        $target = Join-Path $public "assets\$($_.Name)"
        if (-not (Test-Path -LiteralPath $target)) { Copy-Item -LiteralPath $_.FullName -Destination $target }
    }
    # Demais arquivos da raiz (ícone), exceto o index.html, que o Laravel lê da pasta da versão.
    Get-ChildItem -LiteralPath $ReleaseDir -File | Where-Object { $_.Name -notin @('index.html', 'release.json') } |
        ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $public $_.Name) -Force }
}

function Get-Releases {
    if (-not (Test-Path -LiteralPath $releases)) { return @() }
    return @(Get-ChildItem -LiteralPath $releases -Directory | Sort-Object Name)
}

function Remove-OldReleases([int]$KeepCount) {
    $current = Get-Pointer $pointer
    $previous = Get-Pointer $previousPointer
    $all = Get-Releases
    $kept = @($all | Select-Object -Last $KeepCount | ForEach-Object Name) + @($current, $previous) | Where-Object { $_ } | Select-Object -Unique
    foreach ($dir in $all) {
        if ($dir.Name -notin $kept) {
            Remove-Item -LiteralPath $dir.FullName -Recurse -Force
            Write-Host "  versão antiga removida: $($dir.Name)"
        }
    }
    # Arquivos estáticos que nenhuma versão guardada usa.
    $used = @{}
    foreach ($name in $kept) {
        $assets = Join-Path $releases "$name\assets"
        if (Test-Path -LiteralPath $assets) { Get-ChildItem -LiteralPath $assets -File | ForEach-Object { $used[$_.Name] = $true } }
    }
    $publicAssets = Join-Path $public 'assets'
    if (Test-Path -LiteralPath $publicAssets) {
        Get-ChildItem -LiteralPath $publicAssets -File | Where-Object { -not $used.ContainsKey($_.Name) } | Remove-Item -Force
    }
}

function Show-Live {
    try {
        $response = Invoke-WebRequest -Uri 'http://127.0.0.1:8000/' -Method Head -TimeoutSec 5 -SkipHttpErrorCheck
        Write-Host "Servidor (127.0.0.1:8000): HTTP $($response.StatusCode), versão no ar: $($response.Headers['X-Dashboard-Release'])"
    } catch {
        Write-Host 'Servidor em 127.0.0.1:8000 não respondeu (o container pm-backend está no ar?).' -ForegroundColor Yellow
    }
}

function Set-Active([string]$Id) {
    $dir = Join-Path $releases $Id
    if (-not (Test-Path -LiteralPath (Join-Path $dir 'index.html'))) { throw "Versão '$Id' não encontrada em $releases." }
    Copy-StaticFiles $dir
    $current = Get-Pointer $pointer
    if ($current -and $current -ne $Id) { Set-Pointer $previousPointer $current }
    Set-Pointer $pointer $Id
    Write-Host "Versão ativa: $Id" -ForegroundColor Green
}

switch ($PSCmdlet.ParameterSetName) {
    'List' {
        $current = Get-Pointer $pointer
        $previous = Get-Pointer $previousPointer
        foreach ($r in Get-Releases) {
            $mark = if ($r.Name -eq $current) { '* atual' } elseif ($r.Name -eq $previous) { '  anterior' } else { '' }
            Write-Host ("{0,-40} {1}" -f $r.Name, $mark)
        }
        if (-not $current) { Write-Host 'Nenhuma versão publicada.' }
        Show-Live
        exit 0
    }
    'Unpublish' {
        if (Test-Path -LiteralPath $pointer) {
            Set-Pointer $previousPointer (Get-Pointer $pointer)
            Remove-Item -LiteralPath $pointer -Force
        }
        Write-Host 'Dashboard retirado do ar (a API continua). Os arquivos foram mantidos; volte com -Rollback.' -ForegroundColor Yellow
        Show-Live
        exit 0
    }
    'Rollback' {
        $previous = Get-Pointer $previousPointer
        if (-not $previous) { throw 'Não há versão anterior registrada. Use -List e -To <id>.' }
        Set-Active $previous
        Show-Live
        exit 0
    }
    'To' {
        Set-Active $To
        Show-Live
        exit 0
    }
}

# Publicação
Push-Location $dashboard
try {
    if (-not $SkipBuild) {
        pnpm build
        if ($LASTEXITCODE -ne 0) { throw 'O build do dashboard falhou; nada foi publicado.' }
    }
    if (-not (Test-Path -LiteralPath (Join-Path $dist 'index.html'))) { throw 'dashboard/dist/index.html não existe. Rode sem -SkipBuild.' }
    $sha = (git rev-parse --short HEAD).Trim()
    $dirty = if (git status --porcelain -- . ) { '-local' } else { '' }
} finally {
    Pop-Location
}

# Nada de segredos no que vai para o navegador.
$suspicious = Get-ChildItem -LiteralPath $dist -Recurse -File |
    Select-String -Pattern 'AI_SERVICE_TOKEN', 'POSTGRES_PASSWORD', 'APP_KEY', 'base64:[A-Za-z0-9+/]{40}', 'BEGIN (RSA |EC )?PRIVATE KEY', 'pm_runtime' -SimpleMatch:$false |
    Select-Object -First 5
if ($suspicious) {
    $suspicious | ForEach-Object { Write-Host "  $($_.Path):$($_.LineNumber)" -ForegroundColor Red }
    throw 'O build contém algo com cara de segredo; publicação cancelada.'
}
if (Get-ChildItem -LiteralPath $dist -Recurse -Filter '*.map') { throw 'O build contém source maps; publicação cancelada.' }

$id = '{0}-{1}{2}' -f (Get-Date -Format 'yyyyMMdd-HHmmss'), $sha, $dirty
$target = Join-Path $releases $id
New-Item -ItemType Directory -Force -Path $target | Out-Null
Copy-Item -Path (Join-Path $dist '*') -Destination $target -Recurse
@{ id = $id; commit = $sha; built_at = (Get-Date).ToString('o') } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $target 'release.json')

Set-Active $id
Remove-OldReleases $Keep
Show-Live
