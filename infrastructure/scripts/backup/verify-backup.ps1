<#
.SYNOPSIS
    Verifica a integridade dos backups cifrados num destino (ADR-0008, D9). Somente leitura.

.DESCRIPTION
    Para cada manifesto pm-manifest-*.json:
    1. confere se os arquivos listados existem e se o SHA-256 bate com o manifesto;
    2. decifra o dump (autenticação AES-GCM: detecta adulteração, truncamento e chave errada)
       e valida a estrutura com `pg_restore --list`, sem restaurar nada;
    3. decifra o diário de exclusões e confere o formato das linhas.
    O texto em claro fica só em arquivo temporário, apagado em seguida.
    Código de saída 0 = tudo íntegro; 1 = alguma falha.

.EXAMPLE
    pwsh infrastructure/scripts/backup/verify-backup.ps1 -Destination E:\pm-backups
#>
param(
    [Parameter(Mandatory)][string]$Destination,
    [string]$KeyPath = (Join-Path $env:USERPROFILE '.procrastinar-menos\backup.key'),
    [string]$Container = 'pm-postgres',
    [switch]$Latest
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'BackupCrypto.psm1') -Force

if (-not (Test-Path $Destination)) {
    [Console]::Error.WriteLine("Destino indisponível: $Destination")
    exit 1
}

$key = Get-BackupKey $KeyPath
$temp = Join-Path ([System.IO.Path]::GetTempPath()) "pm-verify-$([guid]::NewGuid().ToString('N'))"
New-Item -ItemType Directory -Force -Path $temp | Out-Null
$failures = 0

function Report([string]$Name, [bool]$Ok, [string]$Detail = '') {
    Write-Host ("{0} {1} {2}" -f $(if ($Ok) { '[ OK ]' } else { '[FALHA]' }), $Name, $Detail) -ForegroundColor $(if ($Ok) { 'Green' } else { 'Red' })
    if (-not $Ok) { $script:failures++ }
}

try {
    $manifests = @(Get-ChildItem $Destination -Filter 'pm-manifest-*.json' | Sort-Object Name -Descending)
    if ($Latest) { $manifests = @($manifests | Select-Object -First 1) }
    if ($manifests.Count -eq 0) { Report 'manifestos encontrados' $false 'nenhum backup completo no destino' }

    foreach ($manifestFile in $manifests) {
        $manifest = Get-Content $manifestFile.FullName -Raw | ConvertFrom-Json
        Write-Host "`n== Backup $($manifest.created_at) ($($manifest.database)) ==" -ForegroundColor Cyan

        foreach ($entry in $manifest.files) {
            $path = Join-Path $Destination $entry.name
            if (-not (Test-Path $path)) { Report $entry.name $false 'arquivo ausente'; continue }
            Report "$($entry.name): SHA-256" ((Get-FileHash $path -Algorithm SHA256).Hash -eq $entry.sha256)

            $plain = Join-Path $temp ([guid]::NewGuid().ToString('N'))
            try {
                Unprotect-BackupFile -InputPath $path -OutputPath $plain -Key $key
            } catch {
                Report "$($entry.name): decifragem" $false $_.Exception.Message
                continue
            }

            if ($entry.name -like 'pm-db-*') {
                $remote = "/tmp/pm-verify-$([guid]::NewGuid().ToString('N')).dump"
                docker cp $plain "${Container}:$remote" | Out-Null
                $list = docker exec $Container pg_restore --list $remote 2>&1
                $listOk = $LASTEXITCODE -eq 0
                docker exec $Container rm -f $remote | Out-Null
                $tables = @($list | Where-Object { $_ -match ' TABLE DATA ' }).Count
                Report "$($entry.name): estrutura (pg_restore --list)" ($listOk -and $tables -gt 0) "($tables tabelas com dados)"
            } else {
                $bad = @(Get-Content $plain | Where-Object { $_ -and $_ -notmatch '^[a-z_]+,[0-9a-f-]{36},([0-9a-f-]{36})?,\d{4}-\d{2}-\d{2}T' })
                Report "$($entry.name): formato do diário" ($bad.Count -eq 0) "($((Get-Content $plain | Where-Object { $_ }).Count) entradas)"
            }
            Remove-Item $plain -Force
        }
    }
} finally {
    Remove-Item $temp -Recurse -Force -ErrorAction SilentlyContinue
}

if ($failures -eq 0) { Write-Host "`nBackups íntegros." -ForegroundColor Green; exit 0 }
Write-Host "`n$failures verificação(ões) falharam." -ForegroundColor Red
exit 1
