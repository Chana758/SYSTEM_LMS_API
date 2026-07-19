<?php

namespace App\Http\Requests\Librarian;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLibrarianRequest extends FormRequest
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
     */
    public function rules(): array
    {
        // Get the current user ID to ignore it during the unique email validation
        $userId = $this->route('librarian')?->user_id;

        return [
            'name'         => ['sometimes', 'string', 'max:100'],
            'email'        => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'department'   => ['nullable', 'string', 'max:100'],
            'position'     => ['nullable', 'string', 'max:100'],
            'shift'        => ['nullable', 'string', 'in:morning,afternoon,evening,full_day'],
            'hire_date'    => ['nullable', 'date'],
            'status'       => ['sometimes', 'string', 'in:active,inactive,on_leave'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'This email address is already taken by another user.',
            'shift.in'     => 'The selected shift is invalid.',
            'status.in'    => 'The selected status is invalid.',
        ];
    }
}