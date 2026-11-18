<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            // Mesma regra do cadastro.
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(10)->letters()->numbers()],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Confirme sua senha atual.',
            'password.required' => 'Informe a nova senha.',
            'password.confirmed' => 'A confirmação não confere com a nova senha.',
            'password.different' => 'A nova senha precisa ser diferente da atual.',
        ];
    }
}
