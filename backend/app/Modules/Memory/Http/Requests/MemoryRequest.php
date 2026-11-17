<?php

namespace App\Modules\Memory\Http\Requests;

use App\Modules\Memory\Enums\MemoryKind;
use App\Modules\Memory\Enums\MemoryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Criação (POST) e edição (PATCH) de memórias pela própria pessoa. */
class MemoryRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'kind' => [$required, Rule::enum(MemoryKind::class)],
            'content' => [$required, 'string', 'max:'.config('nexo.memory.max_content_chars')],
            'sensitive' => ['sometimes', 'boolean'],
            'pinned' => ['sometimes', 'boolean'],
            // "Para revisar" é definido pelo sistema; a pessoa ativa ou arquiva.
            'status' => ['sometimes', Rule::in([MemoryStatus::Active->value, MemoryStatus::Archived->value])],
        ];
    }
}
