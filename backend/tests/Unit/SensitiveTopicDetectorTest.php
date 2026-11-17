<?php

namespace Tests\Unit;

use App\Modules\Nexo\Privacy\SensitiveTopicDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SensitiveTopicDetectorTest extends TestCase
{
    public static function sensitive(): array
    {
        return [
            ['Tenho TDAH desde criança.', 'saude'],
            ['Tomo remédio para ansiedade.', 'saude'],
            ['Faço terapia às quintas.', 'saude'],
            ['Vou à igreja aos domingos.', 'religiao'],
            ['Sou filiado a um partido político.', 'politica'],
            ['Sou bissexual.', 'vida_sexual'],
            ['Participo do sindicato da categoria.', 'filiacao_sindical'],
            ['Fiz um teste de DNA.', 'genetica_biometria'],
            ['Sou indígena.', 'origem_racial_etnica'],
        ];
    }

    #[DataProvider('sensitive')]
    public function test_detects_lgpd_categories(string $text, string $category): void
    {
        $this->assertContains($category, (new SensitiveTopicDetector)->categories($text));
    }

    public static function ordinary(): array
    {
        return [
            ['Prefere estudar no início da manhã.'],
            ['Gosta de tocar violão.'],
            ['Está numa missão de ler 12 livros.'],
            ['Está aprendendo Laravel e Python.'],
            ['Trabalha em turnos à noite.'],
            ['Quer melhorar a dicção para apresentações.'],
        ];
    }

    #[DataProvider('ordinary')]
    public function test_ordinary_text_is_not_sensitive(string $text): void
    {
        $this->assertSame([], (new SensitiveTopicDetector)->categories($text));
    }
}
