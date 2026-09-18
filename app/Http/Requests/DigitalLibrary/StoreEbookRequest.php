<?php

namespace App\Http\Requests\DigitalLibrary;

use Illuminate\Foundation\Http\FormRequest;

class StoreEbookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'book_id' => ['required', 'exists:books,id'],
            'file' => ['required', 'file', 'mimes:pdf,epub,mobi', 'max:51200'], // 50MB
            'format' => ['required', 'string', 'in:pdf,epub,mobi'],
            'access_type' => ['required', 'string', 'in:member_only,public'],
            'is_downloadable' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'book_id.required' => __('validation.custom.ebook.book_id.required'),
            'book_id.exists' => __('validation.custom.ebook.book_id.exists'),
            'file.required' => __('validation.custom.ebook.file.required'),
            'file.mimes' => __('validation.custom.ebook.file.mimes'),
            'file.max' => __('validation.custom.ebook.file.max'),
        ];
    }
}