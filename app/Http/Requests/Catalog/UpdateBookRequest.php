<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if ($this->filled('isbn')) {
            $this->merge([
                'isbn' => strtoupper(preg_replace('/[\s-]/', '', $this->input('isbn'))),
            ]);
        }
    }

    public function rules(): array
    {
        $bookId = $this->route('book')?->id;

        // total_qty and status are not editable here:
        // use add-copies / book-copies endpoints (copy status drives availability).
        return [
            'title'          => ['sometimes', 'string', 'max:255'],
            'isbn'           => ['sometimes', 'nullable', 'string', 'regex:/^(\d{9}[\dX]|\d{13})$/', Rule::unique('books', 'isbn')->ignore($bookId)],
            'author'         => ['sometimes', 'string', 'max:150'],
            'publisher'      => ['nullable', 'string', 'max:150'],
            'category_id'    => ['sometimes', 'exists:categories,id'],
            'language'       => ['nullable', 'string', 'max:10'],
            'publish_year'   => ['nullable', 'digits:4', 'integer', 'min:1000', 'max:' . date('Y')],
            'edition'        => ['nullable', 'string', 'max:20'],
            'pages'          => ['nullable', 'integer', 'min:1'],
            'description'    => ['nullable', 'string'],
            'shelf_location' => ['nullable', 'string', 'max:50'],
            'price'          => ['nullable', 'numeric', 'min:0'],
            'acquired_date'  => ['nullable', 'date'],
            'cover_image'    => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'isbn.unique' => __('validation.custom.book.isbn.unique'),
            'isbn.regex'  => 'ISBN must be 10 or 13 digits.',
        ];
    }
}