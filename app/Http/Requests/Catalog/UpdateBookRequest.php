<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $bookId = $this->route('book')?->id;

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'isbn' => ['sometimes', 'string', 'max:20', Rule::unique('books', 'isbn')->ignore($bookId)],
            'author' => ['sometimes', 'string', 'max:150'],
            'publisher' => ['nullable', 'string', 'max:150'],
            'category_id' => ['sometimes', 'exists:categories,id'],
            'language' => ['nullable', 'string', 'max:10'],
            'publish_year' => ['nullable', 'digits:4', 'integer'],
            'edition' => ['nullable', 'string', 'max:20'],
            'pages' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'shelf_location' => ['nullable', 'string', 'max:50'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'acquired_date' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', 'in:available,damaged,lost,archived'],
            'cover_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];

        // Note: total_qty cannot be updated here.
        // Use the dedicated add-copies endpoint instead.
    }

    /**
     * Use the same isbn.unique validation message as StoreBookRequest.
     */
    public function messages(): array
    {
        return [
            'isbn.unique' => __('validation.custom.book.isbn.unique'),
        ];
    }
}