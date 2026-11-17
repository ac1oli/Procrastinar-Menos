<?php

namespace App\Modules\Routine\Http\Requests;

use App\Modules\Routine\Enums\ActivityCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Criação (POST) e atualização parcial (PATCH/PUT) de blocos de rotina. */
class RoutineBlockRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:120'],
            'category' => [$required, Rule::enum(ActivityCategory::class)],
            'days_of_week' => [$required, 'array', 'min:1', 'max:7'],
            'days_of_week.*' => ['integer', 'between:1,7', 'distinct'],
            'start_time' => [$required, 'date_format:H:i'],
            'end_time' => [$required, 'date_format:H:i'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** Compara os horários efetivos: os enviados ou, num PATCH parcial, os já salvos. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $existing = $this->route('routineBlock');
                $start = $this->input('start_time', $existing ? substr($existing->start_time, 0, 5) : null);
                $end = $this->input('end_time', $existing ? substr($existing->end_time, 0, 5) : null);

                if ($start !== null && $start === $end) {
                    $validator->errors()->add('end_time', 'O horário de término deve ser diferente do início.');
                }
            },
        ];
    }

    /** Dias sempre armazenados em ordem crescente. */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key === null && isset($data['days_of_week'])) {
            $data['days_of_week'] = collect($data['days_of_week'])->map(fn ($d) => (int) $d)->sort()->values()->all();
        }

        return $data;
    }
}
