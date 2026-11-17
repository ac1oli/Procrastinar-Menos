# Lê um arquivo .env simples (CHAVE=valor) sem expandir variáveis nem imprimir valores.
function Read-DotEnv([string]$Path) {
    if (-not (Test-Path $Path)) { throw "Arquivo não encontrado: $Path" }
    $values = @{}
    foreach ($line in Get-Content $Path) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$') {
            $values[$Matches[1]] = $Matches[2].Trim('"').Trim("'")
        }
    }
    return $values
}
