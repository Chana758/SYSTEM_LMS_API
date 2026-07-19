<?php

namespace App\Http\Requests\Circulation;

use Illuminate\Foundation\Http\FormRequest;

class StoreBorrowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => ['required', 'exists:members,id'],
            'book_id'   => ['required', 'exists:books,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'member_id.required' => 'Please select a member.',
            'member_id.exists'   => 'The selected member could not be found.',
            'book_id.required'   => 'Please select a book.',
            'book_id.exists'     => 'The selected book could not be found.',
        ];
    }
}