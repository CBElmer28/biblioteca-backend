<?php

namespace App\Http\Requests\Book;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La autorización se maneja en el middleware de rutas
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'isbn' => 'nullable|string|max:20|unique:books,isbn',
            'synopsis' => 'nullable|string',
            'is_digital' => 'boolean',
            
            // Regla Híbrida: Si es digital, DEBE tener URL. Si no lo es, la URL debe ser nula.
            'digital_file_url' => [
                Rule::requiredIf($this->is_digital),
                'nullable',
                'url'
            ],
            
            'publisher' => 'nullable|string|max:255',
            'year' => 'nullable|integer|min:1000|max:' . (date('Y') + 1),
            
            // Relaciones
            'author_ids' => 'required|array',
            'author_ids.*' => 'uuid|exists:authors,id',
            'category_ids' => 'required|array',
            'category_ids.*' => 'uuid|exists:categories,id',
        ];
    }
}