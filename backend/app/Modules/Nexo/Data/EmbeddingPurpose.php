<?php

namespace App\Modules\Nexo\Data;

/** Documento indexado (memória) ou consulta de busca (mensagem da pessoa). */
enum EmbeddingPurpose: string
{
    case Document = 'document';
    case Query = 'query';
}
