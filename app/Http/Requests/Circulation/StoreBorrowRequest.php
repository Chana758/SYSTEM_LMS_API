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
            'member_id.required' => __('validation.custom.borrow.member_id.required'),
            'member_id.exists' => __('validation.custom.borrow.member_id.exists'),
            'book_id.required' => __('validation.custom.borrow.book_id.required'),
            'book_id.exists' => __('validation.custom.borrow.book_id.exists'),
        ];
    }
}