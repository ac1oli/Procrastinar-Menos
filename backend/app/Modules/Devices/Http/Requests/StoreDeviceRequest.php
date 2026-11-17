<?php

namespace App\Modules\Devices\Http\Requests;

use App\Modules\Devices\Enums\DevicePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
        ];
    }
}
