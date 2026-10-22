<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // admin-only check is done in the controller
    }

    public function rules(): array
    {
        return [
            // SystemSettings.vue only edits the `value` field (empty is allowed)
            'value' => ['nullable', 'string'],
        ];
    }
}