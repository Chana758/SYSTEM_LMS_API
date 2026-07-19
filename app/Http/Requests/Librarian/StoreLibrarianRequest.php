<?php

namespace App\Http\Requests\Librarian;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreLibrarianRequest extends FormRequest
{
    /**
     * Authorization is already handled by the 'role:admin' middleware in api.php.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for creating a new Librarian.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'phone' => ['nullable', 'string', 'max:20'],
            'department' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'shift' => ['nullable', 'string', 'in:morning,afternoon,evening,full_day'],
            'hire_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already registered.',
            'password.confirmed' => 'Password confirmation does not match.',
        ];
    }
}