<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
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
            // B2.4: "login" accepts email, student ID or membership no. "email" is kept for old clients.
            'login'    => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email'    => ['required_without:login', 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Custom error messages — pulled from resources/lang/{locale}/validation.php
     * so the response respects the X-Locale header set by SetLocale middleware.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.required_without' => __('validation.custom.login.required'),
            'email.required_without' => __('validation.custom.login.required'),
            'password.required' => __('validation.custom.password.required'),
        ];
    }
}