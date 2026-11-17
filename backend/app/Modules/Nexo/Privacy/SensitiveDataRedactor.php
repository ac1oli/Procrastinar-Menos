<?php

namespace App\Modules\Nexo\Privacy;

/**
 * Mascara identificadores antes de qualquer persistência (ADR-0008, D5).
 *
 * Cobre credenciais, chaves e tokens, cartões (Luhn), CPF e CNPJ (com dígitos
 * verificadores), RG, e-mails e telefones brasileiros. É uma camada de
 * proteção, **não uma garantia**: formatos incomuns passam, e alguns textos
 * comuns podem ser mascarados por engano. A política diz isso à pessoa.
 */
final class SensitiveDataRedactor
{
    public const PLACEHOLDERS = [
        'private_key' => '[chave privada removida]',
        'token' => '[token removido]',
        'credential' => '[credencial removida]',
        'email' => '[e-mail removido]',
        'card' => '[cartão removido]',
        'cnpj' => '[CNPJ removido]',
        'cpf' => '[CPF removido]',
        'rg' => '[RG removido]',
        'phone' => '[telefone removido]',
    ];

    public function redact(string $text): Redaction
    {
        $count = 0;

        $replace = function (string $pattern, string $kind, ?callable $accept = null) use (&$text, &$count): void {
            $text = preg_replace_callback($pattern, function (array $m) use ($kind, $accept, &$count) {
                if ($accept !== null && ! $accept($m)) {
                    return $m[0];
                }
                $count++;

                return ($m['keep'] ?? '').self::PLACEHOLDERS[$kind];
            }, $text) ?? $text;
        };

        $replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', 'private_key');
        $replace('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', 'token');
        $replace('/\b(?:sk|pk|rk)-[A-Za-z0-9_-]{16,}|\bgh[pousr]_[A-Za-z0-9]{20,}|\bxox[abpr]-[A-Za-z0-9-]{10,}|\bAKIA[0-9A-Z]{16}\b|\bAIza[0-9A-Za-z_-]{30,}/', 'token');
        // "senha: x", "minha senha é x", "token=x", "senha do curso: x": mantém o rótulo, remove o valor.
        $keyword = '\b(?:senhas?|password|passwd|pwd|token|api[ _-]?key|chave de api|secret|segredo|pin)\b';
        $replace('/(?<keep>'.$keyword.'\s*(?:[:=]|\bé\b|\beh\b)\s*)\S+/iu', 'credential');
        $replace('/(?<keep>'.$keyword.'(?:\s+[^\s:=]+){1,3}\s*[:=]\s*)(?!\[)\S+/iu', 'credential');
        $replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', 'email');
        $replace('/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/', 'card', fn ($m) => self::luhn(preg_replace('/\D/', '', $m[0])));
        $replace('/(?<!\d)\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}(?!\d)/', 'cnpj', fn ($m) => self::validCnpj(preg_replace('/\D/', '', $m[0])));
        $replace('/(?<!\d)\d{3}\.\d{3}\.\d{3}-\d{2}(?!\d)/', 'cpf');
        $replace('/(?<!\d)\d{11}(?!\d)/', 'cpf', fn ($m) => self::validCpf($m[0]));
        $replace('/(?<!\d)\d{1,2}\.\d{3}\.\d{3}-[\dxX](?![\dxX])/', 'rg');
        $replace('/(?<![\d+])(?:\+?55[\s-]?)?\(?\d{2}\)?[\s-]?9?\d{4}[\s-]?\d{4}(?!\d)/', 'phone');

        return new Redaction($text, $count);
    }

    public function containsIdentifiers(string $text): bool
    {
        return $this->redact($text)->count > 0;
    }

    private static function luhn(string $digits): bool
    {
        if (strlen($digits) < 13 || strlen($digits) > 19 || preg_match('/^(\d)\1+$/', $digits)) {
            return false;
        }

        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $i => $digit) {
            $d = (int) $digit * ($i % 2 === 1 ? 2 : 1);
            $sum += $d > 9 ? $d - 9 : $d;
        }

        return $sum % 10 === 0;
    }

    private static function validCpf(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * ($t + 1 - $i);
            }
            if ((int) $cpf[$t] !== ((10 * $sum) % 11) % 10) {
                return false;
            }
        }

        return true;
    }

    private static function validCnpj(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        foreach ([12 => [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], 13 => [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pos => $weights) {
            $sum = 0;
            foreach ($weights as $i => $w) {
                $sum += (int) $cnpj[$i] * $w;
            }
            $digit = $sum % 11 < 2 ? 0 : 11 - $sum % 11;
            if ((int) $cnpj[$pos] !== $digit) {
                return false;
            }
        }

        return true;
    }
}
