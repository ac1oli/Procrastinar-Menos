<?php

namespace App\Modules\Consent\Http\Requests;

use App\Modules\Consent\Enums\DataAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConsentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'granted' => ['required', 'boolean'],
            // Conceder exige a versão do texto que a pessoa leu.
            'policy_version' => ['required_if:granted,true,1', 'nullable', 'string', 'max:64'],
            // Sem padrão no servidor: excluir ou manter é sempre escolha explícita (D7).
            'data_action' => ['nullable', Rule::enum(DataAction::class)],
        ];
    }
}
