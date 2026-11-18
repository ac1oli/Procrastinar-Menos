<#
.SYNOPSIS
    Backup cifrado do banco do Procrastinar Menos em dispositivo físico separado (ADR-0008, D9).

.DESCRIPTION
    -Mode Full     pg_dump (formato custom) sem os dados de memory_embeddings (regenerados
                   após restaurar) + cópia do diário de exclusões. Rotação: 7 diários + 4 semanais.
    -Mode Journal  Só o diário de exclusões (pequeno). Rodar com frequência (ex.: de hora em
                   hora) reduz a janela em que algo excluído poderia voltar numa restauração.

    Tudo sai cifrado (AES-256-GCM, BackupCrypto.psm1). O texto em claro existe só por
    instantes, em arquivo temporário apagado em seguida. Nada vai para a nuvem nesta fase.

    Destino:
    - Precisa ser preparado UMA vez com -InitializeDestination (grava o marcador
      pm-backup-destino.json). Nas execuções normais o script nunca cria o destino: se o
      dispositivo estiver desconectado, com outra letra ou sem o marcador, ele para com erro
      (código 2) sem gravar nada em lugar nenhum — não há destino alternativo automático.
    - Recusa o mesmo disco físico do sistema e do banco (C:), caminhos de rede e chave
      guardada no próprio destino. -AllowSameDiskForTesting só é aceito dentro da pasta
      temporária do sistema (uso exclusivo do backup-selftest.ps1).

    Somente leitura no banco: não altera nem apaga dados.

.EXAMPLE
    pwsh infrastructure/scripts/backup/backup.ps1 -Destination E:\pm-backups -InitializeDestination
    pwsh infrastructure/scripts/backup/backup.ps1 -Destination E:\pm-backups
    pwsh infrastructure/scripts/backup/backup.ps1 -Destination E:\pm-backups -Mode Journal
#>
param(
    [Parameter(Mandatory)][string]$Destination,
    [ValidateSet('Full', 'Journal')][string]$Mode = 'Full',
    [string]$KeyPath = (Join-Path $env:USERPROFILE '.procrastinar-menos\backup.key'),
    [string]$Database,
    [string]$Container = 'pm-postgres',
    [switch]$InitializeDestination,
    [switch]$AllowSameDiskForTesting
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'BackupCrypto.psm1') -Force
. (Join-Path $PSScriptRoot '..\lib\read-env.ps1')

$envValues = Read-DotEnv (Join-Path $PSScriptRoot '..\..\docker\.env')
$owner = $envValues['POSTGRES_USER']
if (-not $Database) { $Database = $envValues['POSTGRES_DB'] }
if ($Database -notmatch '^[a-z_][a-z0-9_]{0,62}$') { throw "Nome de banco inválido: $Database" }

# --- Destino: dispositivo físico separado, local, sem a chave ------------------------------
if ($Destination -match '^\\\\') { throw 'Destinos de rede não são permitidos nesta fase (sem nuvem).' }
$destinationFull = [System.IO.Path]::GetFullPath($Destination)
$marker = [System.IO.Path]::Combine($destinationFull, 'pm-backup-destino.json')
$root = [System.IO.Path]::GetPathRoot($destinationFull)

if (-not (Test-Path $root)) {
    [Console]::Error.WriteLine("Dispositivo de backup indisponível ($root não existe). Nada foi gravado; o diário de exclusões continua no banco por 35 dias e será copiado na próxima execução.")
    exit 2
}
if ($AllowSameDiskForTesting -and -not $destinationFull.StartsWith([System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath()), [StringComparison]::OrdinalIgnoreCase)) {
    throw '-AllowSameDiskForTesting só é aceito dentro da pasta temporária do sistema (testes).'
}

$keyFull = [System.IO.Path]::GetFullPath($KeyPath)
if ($keyFull.StartsWith($destinationFull, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'A chave não pode ficar no mesmo local dos backups.'
}

function Get-DiskNumber([string]$Path) {
    $letter = ([System.IO.Path]::GetPathRoot([System.IO.Path]::GetFullPath($Path))).Substring(0, 1)
    return (Get-Partition -DriveLetter $letter).DiskNumber
}
$sameDisk = (Get-DiskNumber $destinationFull) -eq (Get-DiskNumber $env:SystemDrive)
if ($sameDisk -and -not $AllowSameDiskForTesting) {
    throw "O destino está no mesmo disco físico do sistema e do banco. Use um dispositivo separado (D9)."
}

if ($InitializeDestination) {
    if (Test-Path $marker) { throw "Destino já preparado ($marker)." }
    New-Item -ItemType Directory -Force -Path $destinationFull | Out-Null
    [ordered]@{
        id = [guid]::NewGuid().ToString()
        created_at = (Get-Date).ToUniversalTime().ToString('o')
        purpose = 'Backups cifrados do Procrastinar Menos (ADR-0008, D9)'
    } | ConvertTo-Json | Set-Content $marker
    Write-Host "Destino preparado: $destinationFull" -ForegroundColor Green
} elseif (-not (Test-Path $marker)) {
    [Console]::Error.WriteLine("Destino sem o marcador pm-backup-destino.json (dispositivo errado, letra trocada ou não preparado). Nada foi gravado. Prepare com -InitializeDestination.")
    exit 2
}

$key = Get-BackupKey $KeyPath
$stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
$temp = Join-Path ([System.IO.Path]::GetTempPath()) "pm-backup-$stamp"
New-Item -ItemType Directory -Force -Path $temp | Out-Null

function Invoke-Psql([string]$Sql) {
    $output = $Sql | docker exec -i $Container psql -X -q -t -A -v ON_ERROR_STOP=1 -U $owner -d $Database
    if ($LASTEXITCODE -ne 0) { throw 'Falha no psql.' }
    return $output
}

function Save-Encrypted([string]$PlainPath, [string]$Name) {
    $target = Join-Path $destinationFull $Name
    Protect-BackupFile -InputPath $PlainPath -OutputPath $target -Key $key
    Remove-Item $PlainPath -Force
    return $target
}

try {
    $written = @()

    # Diário de exclusões (nos dois modos).
    $journalPlain = Join-Path $temp 'journal.csv'
    $journal = Invoke-Psql "COPY (SELECT entity_type, entity_id, user_id, to_char(deleted_at AT TIME ZONE 'UTC', 'YYYY-MM-DD""T""HH24:MI:SS.US""Z""') FROM deletion_journal ORDER BY deleted_at) TO STDOUT WITH CSV"
    Set-Content -Path $journalPlain -Value ($journal -join "`n") -NoNewline
    $written += Save-Encrypted $journalPlain "pm-journal-$stamp.csv.enc"

    if ($Mode -eq 'Full') {
        $remote = "/tmp/pm-backup-$stamp.dump"
        docker exec $Container pg_dump -U $owner -d $Database -Fc --exclude-table-data=memory_embeddings -f $remote
        if ($LASTEXITCODE -ne 0) { throw 'pg_dump falhou.' }
        try {
            $dumpPlain = Join-Path $temp 'db.dump'
            docker cp "${Container}:$remote" $dumpPlain | Out-Null
            if ($LASTEXITCODE -ne 0) { throw 'docker cp falhou.' }
        } finally {
            docker exec $Container rm -f $remote | Out-Null
        }
        $written += Save-Encrypted $dumpPlain "pm-db-$stamp.dump.enc"

        $manifest = [ordered]@{
            created_at = $stamp
            database = $Database
            excluded_table_data = @('memory_embeddings')
            files = @($written | ForEach-Object { [ordered]@{ name = (Split-Path $_ -Leaf); sha256 = (Get-FileHash $_ -Algorithm SHA256).Hash } })
        }
        $manifest | ConvertTo-Json -Depth 4 | Set-Content (Join-Path $destinationFull "pm-manifest-$stamp.json")
    }

    # --- Rotação: 7 diários + 4 semanais; diários de exclusão por 35 dias -------------------
    $dumps = Get-ChildItem $destinationFull -Filter 'pm-db-*.dump.enc' | Sort-Object Name -Descending
    $keep = @($dumps | Select-Object -First 7)
    $weekly = $dumps | Group-Object {
            $day = [datetime]::ParseExact($_.Name.Substring(6, 8), 'yyyyMMdd', $null)
            '{0}-{1}' -f [System.Globalization.ISOWeek]::GetYear($day), [System.Globalization.ISOWeek]::GetWeekOfYear($day)
        } |
        Select-Object -First 4 | ForEach-Object { $_.Group[0] }
    $keep += $weekly
    foreach ($dump in $dumps | Where-Object { $keep.FullName -notcontains $_.FullName }) {
        $suffix = $dump.Name.Substring(6, 16)
        Remove-Item $dump.FullName, (Join-Path $destinationFull "pm-manifest-$suffix.json") -ErrorAction SilentlyContinue
    }
    Get-ChildItem $destinationFull -Filter 'pm-journal-*.csv.enc' |
        Where-Object { $_.LastWriteTimeUtc -lt (Get-Date).ToUniversalTime().AddDays(-35) } |
        Remove-Item

    Write-Host "Backup $Mode concluído em $destinationFull ($($written.Count) arquivo(s) cifrado(s))." -ForegroundColor Green
    if ($sameDisk) { Write-Warning 'Destino no mesmo disco físico (-AllowSameDiskForTesting): use apenas para testes.' }
    $written
} finally {
    Remove-Item $temp -Recurse -Force -ErrorAction SilentlyContinue
}
