<?php

namespace App\Http\Requests\Copy;

use Illuminate\Foundation\Http\FormRequest;

class StoreCopyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'book_id' => 'required|uuid|exists:books,id',
            'copy_code' => 'required|string|unique:copies,copy_code',
            'condition' => 'string|in:good,fair,poor,damaged',
            'location' => 'nullable|string|max:255',
        ];
    }
}