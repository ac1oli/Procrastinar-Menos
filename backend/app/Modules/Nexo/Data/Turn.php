<?php

namespace App\Modules\Nexo\Data;

/** Uma fala de uma conversa enviada à IA como contexto temporário. */
final readonly class Turn
{
    public function __construct(
        public string $role,
        public string $content,
    ) {}

    /** @return array{role: string, content: string} */
    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content];
    }
}
