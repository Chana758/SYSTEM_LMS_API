<?php

namespace App\Http\Requests\MembershipType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by role:admin middleware in api.php
    }

    public function rules(): array
    {
        // code is locked/disabled on edit in MembershipTypesPage.vue,
        // but still validate it defensively on both create and update.
        $typeId = $this->route('membershipType')?->id;

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'string',
                'max:50',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('membership_types', 'code')->ignore($typeId),
            ],
            'label' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => __('validation.custom.membershipType.code.required'),
            'code.unique' => __('validation.custom.membershipType.code.unique'),
            'code.regex' => __('validation.custom.membershipType.code.regex'),
            'label.required' => __('validation.custom.membershipType.label.required'),
        ];
    }
}