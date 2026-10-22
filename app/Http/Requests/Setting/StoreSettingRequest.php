<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class StoreSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // admin-only check is done in the controller
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
            'key.required' => 'The key is required.',
            'key.unique' => 'This key already exists.',
            'key.regex' => 'The key may only contain lowercase letters, numbers and underscores.',
            'type.required' => 'The type is required.',
            'type.in' => 'The type must be string, number, boolean or json.',
        ];
    }
}