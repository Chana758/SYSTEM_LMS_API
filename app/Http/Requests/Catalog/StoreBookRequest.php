<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'isbn' => ['required', 'string', 'max:20', 'unique:books,isbn'],
            'author' => ['required', 'string', 'max:150'],
            'publisher' => ['nullable', 'string', 'max:150'],
            'category_id' => ['required', 'exists:categories,id'],
            'language' => ['nullable', 'string', 'max:10'],
            'publish_year' => ['nullable', 'digits:4', 'integer', 'min:1000', 'max:' . date('Y')],
            'edition' => ['nullable', 'string', 'max:20'],
            'pages' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'total_qty' => ['required', 'integer', 'min:1', 'max:200'],
            'shelf_location' => ['nullable', 'string', 'max:50'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'acquired_date' => ['nullable', 'date'],
            'cover_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    /**
     *  Custom error messages — only isbn.unique needs a translated
     * message; the rest use Laravel's default :attribute-based messages
     * which are already covered by validation.php's core 'attributes'
     * array (see note below).
     */
    public function messages(): array
    {
        return [
            'isbn.unique' => __('validation.custom.book.isbn.unique'),
        ];
    }
}