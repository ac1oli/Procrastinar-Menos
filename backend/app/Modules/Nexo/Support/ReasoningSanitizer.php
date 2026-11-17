<?php

namespace App\Modules\Nexo\Support;

/**
 * Defesa em profundidade: o ai-service já remove o raciocínio interno do
 * modelo, mas o backend nunca entrega ao usuário texto com <think>.
 * Mesma lógica de ai-service/app/sanitize.py.
 */
final class ReasoningSanitizer
{
    public static function strip(string $text): string
    {
        $text = preg_replace('/<think>.*?<\/think>/is', '', $text) ?? '';

        $close = strripos($text, '</think>');
        if ($close !== false) {
            $text = substr($text, $close + strlen('</think>'));
        }

        $open = stripos($text, '<think>');
        if ($open !== false) {
            $text = substr($text, 0, $open);
        }

        return trim($text);
    }
}
