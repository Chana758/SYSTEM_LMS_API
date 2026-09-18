<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class AddCopiesRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     *  Custom error messages — quantity uses Laravel's built-in
     * :attribute placeholder so no dedicated lang key is needed;
     * only the two edge-case messages are worth customizing.
     */
    public function messages(): array
    {
        return [
            'quantity.required' => __('validation.custom.book.copies.required') ?: 'Please enter the quantity to add.',
            'quantity.max' => __('validation.custom.book.copies.max') ?: 'You can add at most 50 copies at a time.',
        ];
    }
}