<?php

namespace App\Modules\Web;

/**
 * Versão publicada do dashboard (ADR-0010).
 *
 * O arquivo "current" guarda só o identificador da versão ativa; trocar esse
 * arquivo é o que publica ou reverte. O id é validado antes de virar caminho.
 */
final class DashboardRelease
{
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/';

    public function __construct(private readonly string $root) {}

    /** Identificador da versão ativa, ou null se nada estiver publicado. */
    public function current(): ?string
    {
        $pointer = $this->root.DIRECTORY_SEPARATOR.'current';
        if (! is_file($pointer)) {
            return null;
        }

        $id = trim((string) file_get_contents($pointer));
        if (preg_match(self::ID_PATTERN, $id) !== 1 || str_contains($id, '..')) {
            return null;
        }

        return is_file($this->indexPathFor($id)) ? $id : null;
    }

    public function indexHtml(string $id): string
    {
        return (string) file_get_contents($this->indexPathFor($id));
    }

    private function indexPathFor(string $id): string
    {
        return implode(DIRECTORY_SEPARATOR, [$this->root, 'releases', $id, 'index.html']);
    }
}
