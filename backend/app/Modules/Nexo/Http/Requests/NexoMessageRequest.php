<?php

namespace App\Modules\Nexo\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NexoMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.config('nexo.max_message_chars')],
        ];
    }
}
