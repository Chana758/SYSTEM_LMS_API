<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /**
     * Determine whether the user is allowed
     * to make this registration request.
     */
    public function authorize(): bool
    {
        // Allow anyone to register
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:100'],
            'email'             => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'          => ['required', 'confirmed', 'min:8'],
            'phone'             => ['required', 'string', 'max:20'],
            'membership_type'   => ['nullable', 'string', 'in:student,teacher,external'],
        ];
    }

    /**
     * Custom error messages — same lang-file pattern as LoginRequest.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('validation.custom.name.required'),
            'name.max' => __('validation.custom.name.max'),
            'email.required' => __('validation.custom.email.required'),
            'email.email' => __('validation.custom.email.email'),
            'email.unique' => __('validation.custom.email.unique'),
            'password.required' => __('validation.custom.password.required'),
            'password.min' => __('validation.custom.password.min'),
            'password.confirmed' => __('validation.custom.password.confirmed'),
            'phone.required' => __('validation.custom.phone.required'),
            'phone.max' => __('validation.custom.phone.max'),
            'membership_type.in' => __('validation.custom.membership_type.in'),
        ];
    }
}