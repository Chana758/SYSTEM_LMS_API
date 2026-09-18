<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:100'],
            'email'             => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'          => ['required', 'confirmed', Password::min(8)],
            'phone'             => ['nullable', 'string', 'max:20'],
            'identity_card_no'  => ['nullable', 'string', 'max:30'],
            'emergency_contact' => ['nullable', 'string', 'max:20'],
            'membership_type'   => ['required', 'string', 'exists:membership_types,code'],
            'max_borrow_limit'  => ['nullable', 'integer', 'min:1', 'max:20'],
            'address'           => ['nullable', 'string', 'max:255'],
            'expiry_date'       => ['nullable', 'date', 'after:today'],
            'status'            => ['nullable', 'string', 'in:active,inactive,expired'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => __('validation.custom.member.email.unique'),
            'password.confirmed' => __('validation.custom.password.confirmed'),
            'membership_type.exists' => __('validation.custom.member.membership_type.exists'),
            'status.in' => __('validation.custom.member.status.in'),
            'expiry_date.after' => __('validation.custom.member.expiry_date.after'),
        ];
    }
}