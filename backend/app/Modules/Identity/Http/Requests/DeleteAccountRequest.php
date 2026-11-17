<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Reconfirmação da senha: a exclusão é irreversível.
            'password' => ['required', 'string', 'current_password:web'],
        ];
    }
}
