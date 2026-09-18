<?php

namespace App\Http\Requests\Librarian;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLibrarianRequest extends FormRequest
{
    /**
     * Authorization is already handled by the 'role:admin' middleware in api.php.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for updating an existing Librarian.
     * Mirrors LibrarianForm.vue's isEdit=true field set — no
     * password/password_confirmation here (that flow is handled
     * separately via QrLoginCardAdmin / staff-issued reissue, not
     * this form).
     */
    public function rules(): array
    {
        // ignore the current librarian's own user_id in the unique email check
        $userId = $this->route('librarian')?->user_id;

        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:20'],
            'department' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'shift' => ['nullable', 'string', 'in:morning,afternoon,evening,full_day'],
            'status' => ['sometimes', 'string', 'in:active,inactive,on_leave'],
            'hire_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => __('validation.custom.librarian.email.unique'),
            'status.in' => __('validation.custom.librarian.status.in'),
        ];
    }
}