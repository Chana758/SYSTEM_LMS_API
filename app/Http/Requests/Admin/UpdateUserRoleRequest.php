<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRoleRequest extends FormRequest
{
    // Access is already restricted by the `role:admin` route middleware.
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'in:admin,librarian,member'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}