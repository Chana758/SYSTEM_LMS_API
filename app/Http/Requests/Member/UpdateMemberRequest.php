<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('member')?->user_id;

        return [
            'name'              => ['sometimes', 'string', 'max:100'],
            'email'             => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone'             => ['nullable', 'string', 'max:20'],
            'identity_card_no'  => ['nullable', 'string', 'max:30'],
            'emergency_contact' => ['nullable', 'string', 'max:20'],
            'membership_type'   => ['sometimes', 'string', 'exists:membership_types,code'],
            'max_borrow_limit'  => ['nullable', 'integer', 'min:1', 'max:20'],
            'address'           => ['nullable', 'string', 'max:255'],
            'expiry_date'       => ['nullable', 'date'],
            'status'            => ['sometimes', 'string', 'in:active,inactive,expired'],
            'remarks'           => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => __('validation.custom.member.email.unique'),
            'membership_type.exists' => __('validation.custom.member.membership_type.exists'),
            'status.in' => __('validation.custom.member.status.in'),
        ];
    }
}