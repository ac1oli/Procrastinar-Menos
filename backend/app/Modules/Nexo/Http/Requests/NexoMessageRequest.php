<?php

namespace App\Modules\Nexo\Http\Requests;

use App\Modules\Nexo\Data\Turn;
use Illuminate\Foundation\Http\FormRequest;

class NexoMessageRequest extends FormRequest
{
    public function rules(): array
    {
        $max = config('nexo.max_message_chars');

        return [
            'message' => ['required', 'string', 'max:'.$max],
            // Conversa privada não é guardada, então não pode continuar uma guardada.
            'private' => ['sometimes', 'boolean'],
            'conversation_id' => ['nullable', 'uuid', 'prohibited_if:private,true,1'],
            // Turnos mantidos pelo navegador quando nada é guardado no servidor.
            'history' => ['sometimes', 'array', 'max:'.(int) config('nexo.history.recent_turns')],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:'.$max],
        ];
    }

    /** @return list<Turn> */
    public function clientHistory(): array
    {
        return array_map(
            fn (array $turn) => new Turn($turn['role'], $turn['content']),
            $this->validated('history', []),
        );
    }
}
