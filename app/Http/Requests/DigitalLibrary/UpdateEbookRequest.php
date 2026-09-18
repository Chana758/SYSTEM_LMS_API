<?php

namespace App\Http\Requests\DigitalLibrary;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEbookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // file is optional on update — "leave empty to keep current" per EbookFormModal.vue
            'file' => ['nullable', 'file', 'mimes:pdf,epub,mobi', 'max:51200'],
            'format' => ['sometimes', 'string', 'in:pdf,epub,mobi'],
            'access_type' => ['sometimes', 'string', 'in:member_only,public'],
            'is_downloadable' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => __('validation.custom.ebook.file.mimes'),
            'file.max' => __('validation.custom.ebook.file.max'),
        ];
    }
}