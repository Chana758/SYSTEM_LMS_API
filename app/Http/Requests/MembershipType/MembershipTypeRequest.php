<?php

namespace App\Http\Requests\MembershipType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    public function rules(): array
    {

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
            'loan_days'    => ['required', 'integer', 'min:1', 'max:365'],
            'max_books'    => ['required', 'integer', 'min:1', 'max:50'],
            'max_renewals' => ['required', 'integer', 'min:0', 'max:10'],
            'renew_days'   => ['required', 'integer', 'min:1', 'max:90'],
            'fine_per_day' => ['required', 'integer', 'min:0', 'max:100000'],
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