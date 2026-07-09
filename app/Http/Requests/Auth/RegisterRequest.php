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
    // Define the validation rules
    // for user registration.
    public function rules(): array
    {
        return [
            'name'              => ['required','string','max:100'],
            'email'             => ['required','email','max:255','unique:users,email'],
            'password'          => ['required','confirmed','min:8'],
            'phone'             => ['required','string','max:20'],
            'membership_type'   => ['nullable', 'string', 'in:student,teacher,external'],
        ];
    }
}
