<#
.SYNOPSIS
    Teste automatizado de backup e restauração (ADR-0008, D9). Só dados sintéticos.

.DESCRIPTION
    1. Cifragem: ida e volta (vazio, múltiplo exato do bloco, vários blocos) e recusa de
       arquivo adulterado, truncado, sem o último bloco ou com chave errada.
    2. Banco: cria pm_backup_selftest_src com as migrations do projeto, insere dados
       sintéticos, faz backup completo, exclui dados (conta, conversa, memória) depois do
       backup, salva o diário, restaura em pm_backup_selftest_restored e confere:
       - o que existia volta; o que foi excluído depois do backup NÃO volta;
       - embeddings ficam fora do backup (reindexação depois);
       - RLS forçada e privilégios do papel de execução sobrevivem à restauração;
       - o arquivo cifrado não contém o texto sintético em claro.
    Não toca no banco principal. Cria e remove apenas bancos pm_backup_selftest_* e
    arquivos num diretório temporário.

.EXAMPLE
    pwsh infrastructure/scripts/backup/backup-selftest.ps1
#>
param([string]$Container = 'pm-postgres', [string]$Backend = 'pm-backend')

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'BackupCrypto.psm1') -Force
. (Join-Path $PSScriptRoot '..\lib\read-env.ps1')

$envValues = Read-DotEnv (Join-Path $PSScriptRoot '..\..\docker\.env')
$owner = $envValues['POSTGRES_USER']
$runtime = $envValues['POSTGRES_RUNTIME_USER']
$src = 'pm_backup_selftest_src'
$restored = 'pm_backup_selftest_restored'
$work = Join-Path ([System.IO.Path]::GetTempPath()) "pm-backup-selftest-$([guid]::NewGuid().ToString('N'))"
$backupDir = Join-Path $work 'destino'
$keyPath = Join-Path $work 'chave\backup.key'
$failures = 0

function Check([string]$Name, [bool]$Ok, [string]$Detail = '') {
    $mark = if ($Ok) { '[ OK ]' } else { '[FALHA]' }
    Write-Host ("{0} {1} {2}" -f $mark, $Name, $Detail) -ForegroundColor $(if ($Ok) { 'Green' } else { 'Red' })
    if (-not $Ok) { $script:failures++ }
}

function Psql([string]$Database, [string]$Sql) {
    $output = $Sql | docker exec -i $Container psql -X -q -t -A -v ON_ERROR_STOP=1 -U $owner -d $Database
    if ($LASTEXITCODE -ne 0) { throw "psql falhou em $Database" }
    return ($output | Where-Object { $_ -ne '' })
}

function Drop-SelftestDatabases {
    foreach ($db in @($src, $restored)) {
        Psql 'postgres' "DROP DATABASE IF EXISTS $db WITH (FORCE)" | Out-Null
    }
}

function Expect-Failure([scriptblock]$Action) {
    try { & $Action; return $false } catch { return $true }
}

New-Item -ItemType Directory -Force -Path $backupDir, (Split-Path $keyPath) | Out-Null

try {
    # --- 1. Cifragem ------------------------------------------------------------------------
    Write-Host "`n== Cifragem ==" -ForegroundColor Cyan
    New-BackupKey -Path $keyPath
    $key = Get-BackupKey $keyPath
    $otherKey = [byte[]]::new(32); [System.Security.Cryptography.RandomNumberGenerator]::Fill($otherKey)

    foreach ($size in @(0, 4MB, (9MB + 123))) {
        $plain = Join-Path $work "plain-$size.bin"; $enc = "$plain.enc"; $back = "$plain.back"
        $bytes = [byte[]]::new($size); [System.Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
        [System.IO.File]::WriteAllBytes($plain, $bytes)
        Protect-BackupFile $plain $enc $key
        Unprotect-BackupFile $enc $back $key
        Check "ida e volta ($size bytes)" ((Get-FileHash $plain).Hash -eq (Get-FileHash $back).Hash)
    }

    $enc = Join-Path $work "plain-$(9MB + 123).bin.enc"
    $data = [System.IO.File]::ReadAllBytes($enc)

    $tampered = [byte[]]$data.Clone(); $tampered[5MB] = $tampered[5MB] -bxor 0xFF
    [System.IO.File]::WriteAllBytes("$enc.tampered", $tampered)
    Check 'recusa arquivo adulterado' (Expect-Failure { Unprotect-BackupFile "$enc.tampered" "$enc.out" $key })

    [System.IO.File]::WriteAllBytes("$enc.truncated", $data[0..($data.Length - 11)])
    Check 'recusa arquivo truncado' (Expect-Failure { Unprotect-BackupFile "$enc.truncated" "$enc.out" $key })

    $firstChunkEnd = 21 + 4 + 4MB + 16
    [System.IO.File]::WriteAllBytes("$enc.nofinal", $data[0..($firstChunkEnd - 1)])
    Check 'recusa arquivo sem o último bloco' (Expect-Failure { Unprotect-BackupFile "$enc.nofinal" "$enc.out" $key })

    Check 'recusa chave errada' (Expect-Failure { Unprotect-BackupFile $enc "$enc.out" $otherKey })
    Check 'não deixa saída parcial após falha' (-not (Test-Path "$enc.out"))

    # --- 2. Banco sintético -----------------------------------------------------------------
    Write-Host "`n== Backup e restauração ==" -ForegroundColor Cyan
    Drop-SelftestDatabases
    Psql 'postgres' "CREATE DATABASE $src OWNER $owner" | Out-Null

    $env:DB_OWNER_USERNAME = $owner
    $env:DB_OWNER_PASSWORD = $envValues['POSTGRES_PASSWORD']
    docker exec -e DB_DATABASE=$src -e DB_OWNER_USERNAME -e DB_OWNER_PASSWORD $Backend php artisan migrate --database=pgsql_owner --force | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'migrations no banco sintético falharam' }
    Remove-Item Env:DB_OWNER_USERNAME, Env:DB_OWNER_PASSWORD

    $marker = 'conteudo-sintetico-de-teste'
    $vector = '[' + ((@('1') + @('0') * 1023) -join ',') + ']'
    Psql $src @"
INSERT INTO users (id, name, email, password, timezone, created_at, updated_at) VALUES
 ('00000000-0000-4000-8000-00000000000a', 'Pessoa Sintética A', 'a@example.com', 'x', 'America/Sao_Paulo', now(), now()),
 ('00000000-0000-4000-8000-00000000000b', 'Pessoa Sintética B', 'b@example.com', 'x', 'America/Sao_Paulo', now(), now());
INSERT INTO consents (id, user_id, scope, granted, policy_version, created_at, updated_at) VALUES
 (gen_random_uuid(), '00000000-0000-4000-8000-00000000000a', 'nexo.memory', true, 'politica-de-memoria@1.0', now(), now());
INSERT INTO conversations (id, user_id, title, last_message_at, created_at, updated_at) VALUES
 ('00000000-0000-4000-8000-0000000000c1', '00000000-0000-4000-8000-00000000000a', 'Mantida', now(), now(), now()),
 ('00000000-0000-4000-8000-0000000000c2', '00000000-0000-4000-8000-00000000000a', 'Excluída depois', now(), now(), now()),
 ('00000000-0000-4000-8000-0000000000c3', '00000000-0000-4000-8000-00000000000b', 'Da conta B', now(), now(), now());
INSERT INTO conversation_messages (id, conversation_id, user_id, role, content, created_at) VALUES
 (gen_random_uuid(), '00000000-0000-4000-8000-0000000000c1', '00000000-0000-4000-8000-00000000000a', 'user', '$marker pergunta', now()),
 (gen_random_uuid(), '00000000-0000-4000-8000-0000000000c1', '00000000-0000-4000-8000-00000000000a', 'assistant', 'resposta', now()),
 (gen_random_uuid(), '00000000-0000-4000-8000-0000000000c2', '00000000-0000-4000-8000-00000000000a', 'user', 'outra', now());
INSERT INTO memories (id, user_id, kind, content, content_hash, origin, evidence_level, confidence, status, confirmed_at, created_at, updated_at) VALUES
 ('00000000-0000-4000-8000-0000000000d1', '00000000-0000-4000-8000-00000000000a', 'preferencia', '$marker memória mantida', repeat('a', 64), 'user_created', 'declarada', 'alta', 'active', now(), now(), now()),
 ('00000000-0000-4000-8000-0000000000d2', '00000000-0000-4000-8000-00000000000a', 'preferencia', 'memória excluída depois', repeat('b', 64), 'user_created', 'declarada', 'alta', 'active', now(), now(), now()),
 ('00000000-0000-4000-8000-0000000000d3', '00000000-0000-4000-8000-00000000000b', 'preferencia', 'memória da conta B', repeat('c', 64), 'user_created', 'declarada', 'alta', 'active', now(), now(), now());
INSERT INTO embedding_models (id, name, dimensions, created_at) VALUES ('00000000-0000-4000-8000-0000000000e1', 'qwen3-embedding:0.6b', 1024, now());
INSERT INTO memory_embeddings (memory_id, user_id, embedding_model_id, content_hash, embedding, created_at) VALUES
 ('00000000-0000-4000-8000-0000000000d1', '00000000-0000-4000-8000-00000000000a', '00000000-0000-4000-8000-0000000000e1', repeat('a', 64), '$vector', now());
"@ | Out-Null

    $backup = & (Join-Path $PSScriptRoot 'backup.ps1') -Destination $backupDir -Database $src -KeyPath $keyPath -InitializeDestination -AllowSameDiskForTesting 3>$null
    $dumpFile = $backup | Where-Object { $_ -like '*pm-db-*' } | Select-Object -First 1
    Check 'backup completo gerado' ([bool]$dumpFile -and (Test-Path $dumpFile))

    $raw = [System.Text.Encoding]::UTF8.GetString([System.IO.File]::ReadAllBytes($dumpFile))
    Check 'dump cifrado não contém texto em claro' (-not $raw.Contains($marker))

    # Exclusões depois do backup (como a pessoa faria pelo app).
    Start-Sleep -Seconds 1
    Psql $src @"
DELETE FROM memories WHERE id = '00000000-0000-4000-8000-0000000000d2';
DELETE FROM conversations WHERE id = '00000000-0000-4000-8000-0000000000c2';
DELETE FROM users WHERE id = '00000000-0000-4000-8000-00000000000b';
"@ | Out-Null
    & (Join-Path $PSScriptRoot 'backup.ps1') -Destination $backupDir -Database $src -KeyPath $keyPath -Mode Journal -AllowSameDiskForTesting 3>$null | Out-Null

    & (Join-Path $PSScriptRoot 'restore.ps1') -BackupFile $dumpFile -TargetDatabase $restored -KeyPath $keyPath | Out-Null
    Check 'restauração em banco novo' ([bool](Psql 'postgres' "SELECT 1 FROM pg_database WHERE datname = '$restored'"))

    $q = { param($sql) (Psql $restored $sql | Select-Object -Last 1) }
    Check 'conta A restaurada; conta B excluída depois do backup não volta' ((& $q "SELECT string_agg(id::text, ',') FROM users") -eq '00000000-0000-4000-8000-00000000000a')
    Check 'memória mantida volta; memória excluída não volta' ((& $q "SELECT string_agg(id::text, ',') FROM memories") -eq '00000000-0000-4000-8000-0000000000d1')
    Check 'conversa excluída não volta (com mensagens)' ((& $q "SELECT count(*) FROM conversations") -eq '1' -and (& $q "SELECT count(*) FROM conversation_messages") -eq '2')
    Check 'consentimentos restaurados' ((& $q "SELECT count(*) FROM consents") -eq '1')
    Check 'embeddings fora do backup (reindexar depois)' ((& $q "SELECT count(*) FROM memory_embeddings") -eq '0')
    Check 'RLS forçada sobrevive à restauração' ((& $q "SELECT bool_and(relrowsecurity AND relforcerowsecurity) FROM pg_class WHERE relname IN ('memories','conversations','consents')") -eq 't')
    Check 'papel de execução mantém só DML' ((& $q "SELECT has_table_privilege('$runtime', 'memories', 'SELECT') AND NOT has_table_privilege('$runtime', 'deletion_journal', 'SELECT')") -eq 't')
    Check 'diário do banco restaurado registra as exclusões reaplicadas' ([int](& $q "SELECT count(*) FROM deletion_journal WHERE entity_type IN ('users','memories','conversations')") -ge 3)

    # --- 3. Integridade e destinos inválidos -------------------------------------------------
    Write-Host "`n== Integridade e destino ==" -ForegroundColor Cyan
    & (Join-Path $PSScriptRoot 'verify-backup.ps1') -Destination $backupDir -KeyPath $keyPath -Container $Container *> $null
    Check 'verify-backup aprova backup íntegro' ($LASTEXITCODE -eq 0)

    $bytes = [System.IO.File]::ReadAllBytes($dumpFile); $bytes[100] = $bytes[100] -bxor 0x01
    [System.IO.File]::WriteAllBytes($dumpFile, $bytes)
    & (Join-Path $PSScriptRoot 'verify-backup.ps1') -Destination $backupDir -KeyPath $keyPath -Container $Container *> $null
    Check 'verify-backup reprova backup adulterado' ($LASTEXITCODE -eq 1)

    $unprepared = Join-Path $work 'sem-marcador'
    New-Item -ItemType Directory -Force -Path $unprepared | Out-Null
    & (Join-Path $PSScriptRoot 'backup.ps1') -Destination $unprepared -Database $src -KeyPath $keyPath -AllowSameDiskForTesting 2>$null 3>$null | Out-Null
    Check 'destino sem marcador: código 2 e nada gravado' ($LASTEXITCODE -eq 2 -and @(Get-ChildItem $unprepared).Count -eq 0)

    $freeLetter = [char[]](90..68) | Where-Object { -not (Test-Path "$($_):\") } | Select-Object -First 1
    & (Join-Path $PSScriptRoot 'backup.ps1') -Destination "$($freeLetter):\pm-backups" -Database $src -KeyPath $keyPath 2>$null 3>$null | Out-Null
    Check "dispositivo desconectado ($($freeLetter):): código 2, sem destino alternativo" ($LASTEXITCODE -eq 2 -and -not (Test-Path "$($freeLetter):\"))

    $outsideTemp = Join-Path $PSScriptRoot 'nao-deve-existir'
    $refused = try { & (Join-Path $PSScriptRoot 'backup.ps1') -Destination $outsideTemp -Database $src -KeyPath $keyPath -InitializeDestination -AllowSameDiskForTesting 3>$null | Out-Null; $false } catch { $true }
    Check 'flag de teste recusada fora da pasta temporária' ($refused -and -not (Test-Path $outsideTemp))
} catch {
    Check 'execução' $false $_.Exception.Message
} finally {
    Drop-SelftestDatabases
    Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Host ''
if ($failures -eq 0) { Write-Host 'Backup e restauração: todos os testes passaram.' -ForegroundColor Green; exit 0 }
Write-Host "$failures verificação(ões) falharam." -ForegroundColor Red
exit 1
