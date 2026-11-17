<?php

namespace App\Modules\Consent\Enums;

/**
 * Destino dos dados já guardados quando um consentimento é revogado (D7).
 *
 * O servidor nunca escolhe sozinho: o cliente precisa enviar a opção. A
 * interface deixa "excluir" pré-selecionado e pede confirmação.
 */
enum DataAction: string
{
    case Delete = 'delete';
    case Keep = 'keep';
}
