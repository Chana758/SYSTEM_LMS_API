<?php

namespace App\Http\Requests\Circulation;

use Illuminate\Foundation\Http\FormRequest;

class RenewBorrowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller checks ownership/role internally
    }

    public function rules(): array
    {
        return [
            // no input fields — the borrow record comes from the route binding
        ];
    }
}