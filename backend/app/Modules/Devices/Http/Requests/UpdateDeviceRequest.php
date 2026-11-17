<?php

namespace App\Modules\Devices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'monitoring_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
