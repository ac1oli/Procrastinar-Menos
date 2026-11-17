<#
.SYNOPSIS
    Restaura um backup cifrado num banco NOVO e reaplica as exclusões posteriores (ADR-0008, D9).

.DESCRIPTION
    1. Decifra o dump (falha se a chave estiver errada ou o arquivo adulterado/truncado).
    2. Cria -TargetDatabase (precisa NÃO existir) e roda pg_restore nele.
       Este script nunca sobrescreve nem apaga um banco existente; a troca do banco
       principal pelo restaurado é um passo manual, documentado em
       docs/security/backup-e-restauracao.md, e exige autorização.
    3. Lê todos os diários de exclusão cifrados do diretório do backup (e, se informado,
       o diário vivo de -LiveJournalDatabase) e reaplica no banco restaurado as exclusões
       feitas depois do backup: contas, conversas, mensagens, memórias e sugestões.
    4. Os embeddings não estão no backup: depois de apontar a aplicação para o banco
       restaurado, rode `php artisan nexo:reindex-memories`.

.EXAMPLE
    pwsh infrastructure/scripts/backup/restore.ps1 -BackupFile E:\pm-backups\pm-db-20261118T030000Z.dump.enc -TargetDatabase pm_restaurado
#>
param(
    [Parameter(Mandatory)][string]$BackupFile,
    [Parameter(Mandatory)][string]$TargetDatabase,
    [string]$JournalDirectory,
    [string]$LiveJournalDatabase,
    [string]$KeyPath = (Join-Path $env:USERPROFILE '.procrastinar-menos\backup.key'),
    [string]$Container = 'pm-postgres'
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'BackupCrypto.psm1') -Force
. (Join-Path $PSScriptRoot '..\lib\read-env.ps1')

$envValues = Read-DotEnv (Join-Path $PSScriptRoot '..\..\docker\.env')
$owner = $envValues['POSTGRES_USER']
foreach ($name in @($TargetDatabase, $LiveJournalDatabase) | Where-Object { $_ }) {
    if ($name -notmatch '^[a-z_][a-z0-9_]{0,62}$') { throw "Nome de banco inválido: $name" }
}

$fileName = Split-Path $BackupFile -Leaf
if ($fileName -notmatch '^pm-db-(\d{8}T\d{6}Z)\.dump\.enc$') { throw 'Nome de arquivo de backup inesperado.' }
$backupStamp = $Matches[1]
$backupTime = [datetime]::ParseExact($backupStamp, "yyyyMMdd'T'HHmmss'Z'", $null, 'AssumeUniversal,AdjustToUniversal')
if (-not $JournalDirectory) { $JournalDirectory = Split-Path (Resolve-Path $BackupFile).Path }

function Invoke-Psql([string]$Database, [string]$Sql) {
    $output = $Sql | docker exec -i $Container psql -X -q -t -A -v ON_ERROR_STOP=1 -U $owner -d $Database
    if ($LASTEXITCODE -ne 0) { throw "Falha no psql ($Database)." }
    return $output
}

$exists = Invoke-Psql 'postgres' "SELECT 1 FROM pg_database WHERE datname = '$TargetDatabase'"
if ($exists) { throw "O banco $TargetDatabase já existe. A restauração só cria bancos novos." }

$key = Get-BackupKey $KeyPath
$temp = Join-Path ([System.IO.Path]::GetTempPath()) "pm-restore-$([guid]::NewGuid().ToString('N'))"
New-Item -ItemType Directory -Force -Path $temp | Out-Null
$remote = "/tmp/pm-restore-$([guid]::NewGuid().ToString('N')).dump"

try {
    # --- 1-2. Decifrar e restaurar ---------------------------------------------------------
    $dumpPlain = Join-Path $temp 'db.dump'
    Unprotect-BackupFile -InputPath $BackupFile -OutputPath $dumpPlain -Key $key
    docker cp $dumpPlain "${Container}:$remote" | Out-Null
    Remove-Item $dumpPlain -Force

    Invoke-Psql 'postgres' "CREATE DATABASE $TargetDatabase OWNER $owner" | Out-Null
    docker exec $Container pg_restore -U $owner -d $TargetDatabase --exit-on-error $remote
    if ($LASTEXITCODE -ne 0) { throw 'pg_restore falhou.' }

    # --- 3. Reunir os diários de exclusão --------------------------------------------------
    $entries = @{}
    $addEntry = {
        param([string]$line)
        $parts = $line.Split(',')
        if ($parts.Count -ne 4) { return }
        $deletedAt = [datetime]::Parse($parts[3], $null, 'AssumeUniversal,AdjustToUniversal')
        # Margem de 5 min: o dump não é instantâneo.
        if ($deletedAt -ge $backupTime.AddMinutes(-5)) { $entries["$($parts[0])|$($parts[1])"] = $parts }
    }

    foreach ($file in Get-ChildItem $JournalDirectory -Filter 'pm-journal-*.csv.enc') {
        $plain = Join-Path $temp "$($file.BaseName).csv"
        Unprotect-BackupFile -InputPath $file.FullName -OutputPath $plain -Key $key
        Get-Content $plain | Where-Object { $_ } | ForEach-Object { & $addEntry $_ }
        Remove-Item $plain -Force
    }
    if ($LiveJournalDatabase) {
        Invoke-Psql $LiveJournalDatabase "COPY (SELECT entity_type, entity_id, user_id, to_char(deleted_at AT TIME ZONE 'UTC', 'YYYY-MM-DD""T""HH24:MI:SS.US""Z""') FROM deletion_journal) TO STDOUT WITH CSV" |
            Where-Object { $_ } | ForEach-Object { & $addEntry $_ }
    }

    # --- 3b. Reaplicar exclusões (contas primeiro: a cascata leva o resto) -----------------
    $allowed = @('users', 'conversations', 'conversation_messages', 'memories', 'memory_suggestions')
    $applied = [ordered]@{}
    foreach ($table in $allowed) {
        $ids = @($entries.Values | Where-Object { $_[0] -eq $table } | ForEach-Object { $_[1] } |
            Where-Object { $_ -match '^[0-9a-f-]{36}$' })
        if ($ids.Count -eq 0) { $applied[$table] = 0; continue }
        $list = ($ids | ForEach-Object { "'$_'" }) -join ','
        $count = Invoke-Psql $TargetDatabase "WITH d AS (DELETE FROM $table WHERE id IN ($list) RETURNING 1) SELECT count(*) FROM d"
        $applied[$table] = [int]($count | Select-Object -Last 1)
    }

    # Os gatilhos do banco restaurado registram essas exclusões no diário dele,
    # para que backups futuros também as carreguem.

    Write-Host "Restaurado em $TargetDatabase (backup de $backupStamp)." -ForegroundColor Green
    Write-Host ("Exclusões reaplicadas: " + (($applied.GetEnumerator() | ForEach-Object { "$($_.Key)=$($_.Value)" }) -join ', '))
    Write-Host 'Próximo passo: apontar a aplicação para o banco restaurado e rodar php artisan nexo:reindex-memories.'
    [pscustomobject]@{ Database = $TargetDatabase; BackupStamp = $backupStamp; Applied = $applied }
} finally {
    docker exec $Container rm -f $remote | Out-Null
    Remove-Item $temp -Recurse -Force -ErrorAction SilentlyContinue
}
