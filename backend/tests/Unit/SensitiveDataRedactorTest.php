<?php

namespace Tests\Unit;

use App\Modules\Nexo\Privacy\SensitiveDataRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Todos os dados abaixo são fictícios (CPF/CNPJ de exemplo, cartão de teste). */
class SensitiveDataRedactorTest extends TestCase
{
    public static function identifiers(): array
    {
        return [
            'CPF formatado' => ['Meu CPF é 123.456.789-09.', '123.456.789-09', '[CPF removido]'],
            'CPF sem pontuação' => ['cpf 12345678909 ok', '12345678909', '[CPF removido]'],
            'CNPJ' => ['empresa 11.222.333/0001-81', '11.222.333/0001-81', '[CNPJ removido]'],
            'cartão de teste' => ['cartão 4111 1111 1111 1111 vence logo', '4111 1111 1111 1111', '[cartão removido]'],
            'cartão colado' => ['4111111111111111', '4111111111111111', '[cartão removido]'],
            'e-mail' => ['escreva para pessoa.teste@example.com', 'pessoa.teste@example.com', '[e-mail removido]'],
            'celular' => ['me liga no (11) 98765-4321', '98765-4321', '[telefone removido]'],
            'celular com DDI' => ['+55 11 98765 4321', '98765 4321', '[telefone removido]'],
            'RG' => ['RG 12.345.678-9', '12.345.678-9', '[RG removido]'],
            'senha declarada' => ['minha senha é Tr0ub4dor&3', 'Tr0ub4dor&3', 'senha é [credencial removida]'],
            'senha com dois pontos' => ['senha: hunter2', 'hunter2', 'senha: [credencial removida]'],
            'token de API' => ['use sk-abcdefghijklmnop1234 no teste', 'sk-abcdefghijklmnop1234', '[token removido]'],
            'token GitHub' => ['ghp_abcdefghijklmnopqrstuvwxyz0123', 'ghp_abcdefghijklmnopqrstuvwxyz0123', '[token removido]'],
            'JWT' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.abcdefghijklmnop', 'eyJhbGciOiJIUzI1NiJ9', '[token removido]'],
            'chave privada' => ["-----BEGIN PRIVATE KEY-----\nMIIabc\n-----END PRIVATE KEY-----", 'MIIabc', '[chave privada removida]'],
        ];
    }

    #[DataProvider('identifiers')]
    public function test_masks_identifiers(string $input, string $secret, string $expected): void
    {
        $result = (new SensitiveDataRedactor)->redact($input);

        $this->assertStringNotContainsString($secret, $result->text);
        $this->assertStringContainsString($expected, $result->text);
        $this->assertGreaterThanOrEqual(1, $result->count);
    }

    public static function ordinaryText(): array
    {
        return [
            'horários e datas' => ['Estudei das 19:00 às 21:00 em 2026-11-17.'],
            'metas numéricas' => ['Quero ler 12 livros em 2026 e fazer 3 cursos.'],
            'intervalo de anos' => ['Trabalhei lá de 2019-2023.'],
            'número qualquer de 11 dígitos inválido como CPF' => ['Pedido 12345678901 chegou.'],
            'cartão que falha no Luhn' => ['Código 4111 1111 1111 1112.'],
            'palavra senha sem valor' => ['Esqueci a senha do curso, vou redefinir.'],
        ];
    }

    #[DataProvider('ordinaryText')]
    public function test_leaves_ordinary_text_untouched(string $input): void
    {
        $result = (new SensitiveDataRedactor)->redact($input);

        $this->assertSame($input, $result->text);
        $this->assertSame(0, $result->count);
    }

    public function test_counts_every_replacement(): void
    {
        $result = (new SensitiveDataRedactor)->redact('CPF 123.456.789-09 e e-mail a@example.com');

        $this->assertSame(2, $result->count);
    }
}
