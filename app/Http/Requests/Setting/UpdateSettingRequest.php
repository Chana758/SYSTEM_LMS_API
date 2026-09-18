<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by role:admin middleware in api.php
    }

    public function rules(): array
    {
        return [
            // SystemSettings.vue only ever PATCHes the `value` field per row
            'value' => ['nullable', 'string'],
        ];
    }
}