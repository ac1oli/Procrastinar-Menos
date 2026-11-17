<?php

namespace App\Modules\Goals\Http\Requests;

use App\Modules\Goals\Enums\GoalStatus;
use App\Modules\Goals\Enums\GoalType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Criação (POST) e atualização parcial (PATCH/PUT) de objetivos. */
class GoalRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'type' => [$required, Rule::enum(GoalType::class)],
            'status' => ['sometimes', Rule::enum(GoalStatus::class)],
            'target_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
