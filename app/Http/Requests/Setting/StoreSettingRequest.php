<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class StoreSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by role:admin middleware in api.php
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100', 'unique:settings,key', 'regex:/^[a-z0-9_]+$/'],
            'value' => ['nullable', 'string'],
            'type' => ['required', 'string', 'in:string,number,boolean,json'],
            'group' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'key.required' => __('validation.custom.setting.key.required'),
            'key.unique' => __('validation.custom.setting.key.unique'),
            'key.regex' => __('validation.custom.setting.key.regex'),
            'type.required' => __('validation.custom.setting.type.required'),
            'type.in' => __('validation.custom.setting.type.in'),
        ];
    }
}