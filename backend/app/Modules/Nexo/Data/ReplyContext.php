<?php

namespace App\Modules\Nexo\Data;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Contexto autorizado enviado à IA. Só entra aqui o que o backend decidiu
 * compartilhar; hoje, apenas fuso e horário local (sem nome nem e-mail).
 */
final readonly class ReplyContext
{
    public function __construct(
        public string $timezone,
        public string $localDateTime,
    ) {}

    public static function forUser(User $user): self
    {
        return new self(
            timezone: $user->timezone,
            localDateTime: CarbonImmutable::now($user->timezone)->toIso8601String(),
        );
    }

    /** @return array{timezone: string, local_datetime: string} */
    public function toArray(): array
    {
        return ['timezone' => $this->timezone, 'local_datetime' => $this->localDateTime];
    }
}
