<?php

namespace App\Modules\Nexo\Data;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Contexto temporário enviado à IA (ADR-0008 §2.1). Nunca é persistido.
 *
 * Só entra aqui o que o backend decidiu compartilhar, conforme os
 * consentimentos: fuso e horário (sempre), memórias recuperadas
 * (nexo.memory) e recorte de rotina e objetivos (nexo.context.*).
 * Nome e e-mail nunca entram.
 */
final readonly class ReplyContext
{
    /**
     * @param  list<array{kind: string, content: string}>  $memories
     * @param  array{routine_today: list<string>, goals: list<string>}|null  $profile
     */
    public function __construct(
        public string $timezone,
        public string $localDateTime,
        public array $memories = [],
        public ?array $profile = null,
    ) {}

    public static function forUser(User $user): self
    {
        return new self(
            timezone: $user->timezone,
            localDateTime: CarbonImmutable::now($user->timezone)->toIso8601String(),
        );
    }

    /**
     * @param  list<array{kind: string, content: string}>  $memories
     * @param  array{routine_today: list<string>, goals: list<string>}|null  $profile
     */
    public function with(array $memories, ?array $profile): self
    {
        return new self($this->timezone, $this->localDateTime, $memories, $profile);
    }

    public function toArray(): array
    {
        $context = [
            'timezone' => $this->timezone,
            'local_datetime' => $this->localDateTime,
            'memories' => $this->memories,
        ];

        if ($this->profile !== null) {
            $context['profile'] = $this->profile;
        }

        return $context;
    }
}
