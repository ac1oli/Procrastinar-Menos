<?php

namespace App\Modules\Consent\Events;

use App\Models\User;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Consent\Enums\DataAction;

/**
 * Disparado dentro da transação da revogação. Cada módulo dono de dados
 * (histórico, memória) reage aplicando a escolha da pessoa.
 */
final readonly class ConsentRevoked
{
    public function __construct(
        public User $user,
        public ConsentScope $scope,
        public ?DataAction $dataAction,
    ) {}
}
