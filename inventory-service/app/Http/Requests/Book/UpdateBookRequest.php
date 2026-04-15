<?php

namespace App\Http\Requests\Book;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateBookRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $bookId = $this->route('id');

        return [
            'title'            => ['sometimes', 'string', 'max:255', 'min:2'],
            'subtitle'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'synopsis'         => ['sometimes', 'nullable', 'string', 'max:8000'],
            'cover_url'        => ['sometimes', 'nullable', 'url', 'max:500'],
            'publisher'        => ['sometimes', 'nullable', 'string', 'max:200'],
            'publication_year' => ['sometimes', 'nullable', 'integer', 'min:1450'],
            'dewey_code'       => ['sometimes', 'nullable', 'string', 'max:20'],
            'location_hint'    => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active'        => ['sometimes', 'boolean'],

            // is_digital no puede cambiar una vez creado el libro
            // (implicaría crear o destruir copias físicas — operación destructiva)
            'is_digital'       => ['prohibited'],

            'digital_file_url' => ['sometimes', 'nullable', 'url', 'max:500'],

            'isbn_13' => [
                'sometimes', 'nullable', 'string', 'size:13',
                Rule::unique('books', 'isbn_13')->ignore($bookId),
            ],

            'authors'          => ['sometimes', 'array', 'min:1'],
            'authors.*.id'     => ['required_with:authors', 'string', 'exists:authors,id'],
            'authors.*.role'   => ['required_with:authors', Rule::in(['primary', 'coauthor', 'translator', 'editor'])],

            'categories'       => ['sometimes', 'array'],
            'categories.*'     => ['string', 'exists:categories,id'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Error de validación.',
            'errors'  => $validator->errors(),
        ], 422));
    }
}