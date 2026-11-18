<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Perfil: só os campos que já existem (nome de exibição e fuso). Nenhum dado pessoal novo.
 */
class UpdateProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            // Espaços repetidos viram um só; bordas são removidas.
            $this->merge(['name' => trim((string) preg_replace('/\s+/u', ' ', $this->input('name')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:120', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe um nome de exibição.',
            'name.max' => 'O nome pode ter até 120 caracteres.',
            'name.not_regex' => 'O nome contém caracteres invisíveis ou de controle.',
            'timezone.required' => 'Escolha um fuso horário.',
            'timezone.timezone' => 'Fuso horário desconhecido. Use um identificador IANA, como America/Sao_Paulo.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->has('name') && ! $this->has('timezone')) {
                $validator->errors()->add('name', 'Nada para atualizar.');
            }
        });
    }
}
