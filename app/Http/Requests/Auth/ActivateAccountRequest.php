<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** B2.2: self-activation of an imported student account. */
class ActivateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id'    => ['required', 'string', 'max:30'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'password'      => ['required', 'confirmed', Password::min(8)],
            'pin'           => ['required', 'digits:4', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.required'    => __('validation.custom.student_id.required'),
            'date_of_birth.required' => __('validation.custom.date_of_birth.required'),
            'password.required'      => __('validation.custom.password.required'),
            'password.min'           => __('validation.custom.password.min'),
            'password.confirmed'     => __('validation.custom.password.confirmed'),
            'pin.required'           => __('validation.custom.pin.required'),
            'pin.digits'             => __('validation.custom.pin.digits'),
            'pin.confirmed'          => __('validation.custom.pin.confirmed'),
        ];
    }
}
