<?php

namespace App\Modules\Memory;

use App\Models\User;
use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Memory\Enums\MemorySensitivity;
use App\Modules\Nexo\Privacy\SensitiveDataRedactor;
use App\Modules\Nexo\Privacy\SensitiveTopicDetector;
use Illuminate\Validation\ValidationException;

/**
 * Regras de conteúdo de memória (ADR-0008 §2.3, D4).
 *
 * - Identificadores (documentos, cartões, contatos, credenciais): sempre recusados.
 * - Categorias sensíveis: só em criação/edição manual e com nexo.memory.sensitive.
 * - Sugestões do NEXO com qualquer sinal sensível são descartadas.
 */
final class MemoryGuard
{
    public function __construct(
        private readonly SensitiveDataRedactor $redactor,
        private readonly SensitiveTopicDetector $topics,
        private readonly ConsentService $consents,
    ) {}

    /**
     * Valida conteúdo escrito pela própria pessoa e decide a sensibilidade.
     *
     * @throws ValidationException
     */
    public function forManualContent(User $user, string $content, bool $markedSensitive): MemorySensitivity
    {
        $this->rejectIdentifiers($content);

        $sensitive = $markedSensitive || $this->topics->isSensitive($content);
        if ($sensitive && ! $this->consents->allows($user, ConsentScope::MemorySensitive)) {
            throw ValidationException::withMessages([
                'content' => 'Este conteúdo parece ser um dado sensível (saúde, religião, política, vida sexual, origem étnica, '
                    .'filiação sindical, genética ou biometria). Para guardá-lo, ative a autorização de memórias sensíveis '
                    .'ou reformule o texto.',
            ]);
        }

        return $sensitive ? MemorySensitivity::Sensitive : MemorySensitivity::Normal;
    }

    /** Sugestões inferidas: qualquer sinal de identificador ou de tema sensível descarta. */
    public function acceptsSuggestion(string $content, string $modelSensitivity): bool
    {
        return $modelSensitivity === MemorySensitivity::Normal->value
            && ! $this->redactor->containsIdentifiers($content)
            && ! $this->topics->isSensitive($content);
    }

    /** @throws ValidationException */
    public function rejectIdentifiers(string $content): void
    {
        if ($this->redactor->containsIdentifiers($content)) {
            throw ValidationException::withMessages([
                'content' => 'Memórias não podem conter documentos, cartões, contatos, senhas ou outras credenciais.',
            ]);
        }
    }
}
