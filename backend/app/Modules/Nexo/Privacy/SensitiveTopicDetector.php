<?php

namespace App\Modules\Nexo\Privacy;

use Illuminate\Support\Str;

/**
 * Detecta categorias de dado sensível da LGPD (art. 5º, II) por palavras-chave.
 *
 * Conservador de propósito: prefere marcar a mais (falso positivo) a deixar
 * passar. Não é infalível — sinônimos, gírias e erros de digitação escapam —
 * e por isso é só uma das camadas (ADR-0008, D4/D5).
 */
final class SensitiveTopicDetector
{
    /** Radicais sem acento; casam no início de palavra. "$" no fim exige a palavra inteira. */
    private const CATEGORIES = [
        'saude' => [
            'tdah', 'deficit de atencao', 'hiperativ', 'ansiedade', 'ansios', 'depress', 'diagnostic', 'medicament',
            'remedio', 'terapia', 'terapeuta', 'psiquiatr', 'psicolog', 'doenca', 'doente', 'cancer', 'diabet',
            'hipertens', 'transtorno', 'autis', 'bipolar', 'insonia', 'gravid', 'gestante', 'hiv$', 'deficiencia',
            'sindrome', 'quimioterap', 'cirurgia', 'ritalina', 'venvanse', 'antidepressiv', 'ansiolitic', 'burnout',
            'panico', 'toc$', 'esquizofren', 'dislexi', 'epilep', 'tratamento medico', 'saude mental',
        ],
        'religiao' => [
            'religi', 'igreja', 'evangelic', 'catolic', 'espirita', 'espiritismo', 'umbanda', 'candomble', 'islam',
            'muculman', 'judaism', 'judeu', 'judia$', 'ateu$', 'ateia$', 'budis', 'pastor$', 'missa$',
        ],
        'politica' => ['partido politic', 'filiad', 'eleitor', 'ideologia', 'politicamente', 'militancia', 'militante'],
        'vida_sexual' => [
            'sexual', 'sexo$', 'gay$', 'lesbica', 'bissexual', 'transexual', 'transgener', 'lgbt', 'homossexual',
            'heterossexual', 'assexual',
        ],
        'origem_racial_etnica' => ['etnia', 'etnic', 'racial', 'indigena', 'quilombola'],
        'filiacao_sindical' => ['sindicato', 'sindical', 'sindicaliz'],
        'genetica_biometria' => ['genetic', 'genoma', 'dna$', 'biometri', 'impressao digital', 'reconhecimento facial'],
    ];

    /** @return list<string> categorias encontradas */
    public function categories(string $text): array
    {
        $normalized = ' '.Str::lower(Str::ascii($text)).' ';
        $found = [];

        foreach (self::CATEGORIES as $category => $stems) {
            foreach ($stems as $stem) {
                $pattern = str_ends_with($stem, '$')
                    ? preg_quote(substr($stem, 0, -1), '/').'(?![a-z0-9])'
                    : preg_quote($stem, '/');

                if (preg_match('/(?<![a-z0-9])'.$pattern.'/', $normalized) === 1) {
                    $found[] = $category;
                    break;
                }
            }
        }

        return $found;
    }

    public function isSensitive(string $text): bool
    {
        return $this->categories($text) !== [];
    }
}
