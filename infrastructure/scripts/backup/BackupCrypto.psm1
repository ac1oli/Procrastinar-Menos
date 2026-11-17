<#
    Cifragem dos backups (ADR-0008, D9): AES-256-GCM em blocos autenticados.

    Formato: cabeçalho "PMBK" + versão (1) + nonce base (12) + tamanho do bloco (4).
    Cada bloco: tamanho do texto (4) + texto cifrado + tag (16). O nonce de cada
    bloco deriva do nonce base e do índice; o AAD inclui cabeçalho, índice e a
    marca de último bloco. Assim, alteração, reordenação, troca de chave ou
    truncamento do arquivo são detectados na decifragem.

    A chave (32 bytes, base64) fica fora do repositório e fora do dispositivo
    de backup. Sem ela, os backups são irrecuperáveis: guarde uma cópia num
    gerenciador de senhas.
#>

Set-StrictMode -Version Latest

$script:Magic = [byte[]][char[]]'PMBK'
$script:Version = [byte]1
$script:ChunkSize = 4MB
$script:TagSize = 16

function New-BackupKey {
    param([Parameter(Mandatory)][string]$Path)

    if (Test-Path $Path) { throw "Já existe uma chave em $Path; não será sobrescrita." }
    New-Item -ItemType Directory -Force -Path (Split-Path $Path) | Out-Null
    $key = [byte[]]::new(32)
    [System.Security.Cryptography.RandomNumberGenerator]::Fill($key)
    Set-Content -Path $Path -Value ([Convert]::ToBase64String($key)) -NoNewline

    # Somente o usuário atual pode ler a chave.
    $acl = Get-Acl $Path
    $acl.SetAccessRuleProtection($true, $false)
    $rule = [System.Security.AccessControl.FileSystemAccessRule]::new(
        [System.Security.Principal.WindowsIdentity]::GetCurrent().Name, 'FullControl', 'Allow')
    $acl.SetAccessRule($rule)
    Set-Acl -Path $Path -AclObject $acl
}

function Get-BackupKey {
    param([Parameter(Mandatory)][string]$Path)

    if (-not (Test-Path $Path)) { throw "Chave de backup não encontrada em $Path (crie com New-BackupKey)." }
    $key = [Convert]::FromBase64String((Get-Content $Path -Raw).Trim())
    if ($key.Length -ne 32) { throw 'A chave de backup deve ter 32 bytes (base64).' }
    return $key
}

function Get-ChunkNonce([byte[]]$BaseNonce, [long]$Index) {
    $nonce = [byte[]]$BaseNonce.Clone()
    $counter = [BitConverter]::GetBytes([uint32]$Index)
    for ($i = 0; $i -lt 4; $i++) { $nonce[8 + $i] = $nonce[8 + $i] -bxor $counter[$i] }
    return $nonce
}

function Get-ChunkAad([byte[]]$Header, [long]$Index, [bool]$Final) {
    return [byte[]]($Header + [BitConverter]::GetBytes([uint64]$Index) + [byte]([int]$Final))
}

function Protect-BackupFile {
    param(
        [Parameter(Mandatory)][string]$InputPath,
        [Parameter(Mandatory)][string]$OutputPath,
        [Parameter(Mandatory)][byte[]]$Key
    )

    $baseNonce = [byte[]]::new(12)
    [System.Security.Cryptography.RandomNumberGenerator]::Fill($baseNonce)
    $header = [byte[]]($script:Magic + $script:Version + $baseNonce + [BitConverter]::GetBytes([int]$script:ChunkSize))

    $aes = [System.Security.Cryptography.AesGcm]::new($Key, $script:TagSize)
    $in = [System.IO.File]::OpenRead($InputPath)
    $out = [System.IO.File]::Create($OutputPath)
    try {
        $out.Write($header, 0, $header.Length)
        $buffer = [byte[]]::new($script:ChunkSize)
        $index = 0L
        $read = $in.Read($buffer, 0, $buffer.Length)
        do {
            $next = [byte[]]::new($script:ChunkSize)
            $nextRead = if ($read -eq $buffer.Length) { $in.Read($next, 0, $next.Length) } else { 0 }
            $final = $nextRead -eq 0

            $plain = [byte[]]::new($read)
            [Array]::Copy($buffer, $plain, $read)
            $cipher = [byte[]]::new($read)
            $tag = [byte[]]::new($script:TagSize)
            $aes.Encrypt((Get-ChunkNonce $baseNonce $index), $plain, $cipher, $tag, (Get-ChunkAad $header $index $final))

            $length = [BitConverter]::GetBytes([int]$read)
            $out.Write($length, 0, 4)
            $out.Write($cipher, 0, $cipher.Length)
            $out.Write($tag, 0, $tag.Length)

            $buffer = $next
            $read = $nextRead
            $index++
        } while (-not $final)
    } finally {
        $in.Dispose(); $out.Dispose(); $aes.Dispose()
    }
}

function Unprotect-BackupFile {
    param(
        [Parameter(Mandatory)][string]$InputPath,
        [Parameter(Mandatory)][string]$OutputPath,
        [Parameter(Mandatory)][byte[]]$Key
    )

    $aes = [System.Security.Cryptography.AesGcm]::new($Key, $script:TagSize)
    $in = [System.IO.File]::OpenRead($InputPath)
    $out = [System.IO.File]::Create($OutputPath)
    $ok = $false
    try {
        $header = [byte[]]::new(21)
        if ($in.Read($header, 0, 21) -ne 21 -or [Text.Encoding]::ASCII.GetString($header, 0, 4) -ne 'PMBK' -or $header[4] -ne 1) {
            throw 'Arquivo não é um backup do Procrastinar Menos (cabeçalho inválido).'
        }
        $baseNonce = $header[5..16]
        $chunkSize = [BitConverter]::ToInt32($header, 17)
        $index = 0L
        $sawFinal = $false

        while ($true) {
            $lengthBytes = [byte[]]::new(4)
            $got = $in.Read($lengthBytes, 0, 4)
            if ($got -eq 0) { break }
            if ($sawFinal) { throw 'Dados após o último bloco: arquivo adulterado.' }
            if ($got -ne 4) { throw 'Arquivo truncado.' }
            $length = [BitConverter]::ToInt32($lengthBytes, 0)
            if ($length -lt 0 -or $length -gt $chunkSize) { throw 'Bloco com tamanho inválido: arquivo adulterado.' }

            $cipher = [byte[]]::new($length)
            $tag = [byte[]]::new($script:TagSize)
            if ($in.Read($cipher, 0, $length) -ne $length -or $in.Read($tag, 0, $script:TagSize) -ne $script:TagSize) {
                throw 'Arquivo truncado.'
            }

            # O último bloco é o que tem tamanho menor que o máximo; um bloco cheio
            # ainda pode ser o último (tamanho exato múltiplo), então tentamos os dois.
            $plain = [byte[]]::new($length)
            $decrypted = $false
            foreach ($final in @(($length -lt $chunkSize), ($length -eq $chunkSize))) {
                try {
                    $aes.Decrypt((Get-ChunkNonce $baseNonce $index), $cipher, $tag, $plain, (Get-ChunkAad $header $index $final))
                    $sawFinal = $final
                    $decrypted = $true
                    break
                } catch [System.Security.Cryptography.AuthenticationTagMismatchException] { }
            }
            if (-not $decrypted) { throw 'Falha de autenticação: chave errada ou arquivo adulterado.' }

            $out.Write($plain, 0, $plain.Length)
            $index++
        }

        if (-not $sawFinal) { throw 'Arquivo truncado: último bloco ausente.' }
        $ok = $true
    } finally {
        $in.Dispose(); $out.Dispose(); $aes.Dispose()
        if (-not $ok) { Remove-Item $OutputPath -ErrorAction SilentlyContinue }
    }
}

Export-ModuleMember -Function New-BackupKey, Get-BackupKey, Protect-BackupFile, Unprotect-BackupFile
