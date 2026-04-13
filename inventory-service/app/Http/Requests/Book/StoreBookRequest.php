<?php

namespace App\Http\Requests\Book;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreBookRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            // ── Identificadores ───────────────────────────────────────────────
            'isbn_13'          => ['nullable', 'string', 'size:13', 'unique:books,isbn_13'],
            'isbn_10'          => ['nullable', 'string', 'size:10', 'unique:books,isbn_10'],

            // ── Metadatos ─────────────────────────────────────────────────────
            'title'            => ['required', 'string', 'max:255', 'min:2'],
            'subtitle'         => ['nullable', 'string', 'max:255'],
            'synopsis'         => ['nullable', 'string', 'max:8000'],
            'cover_url'        => ['nullable', 'url', 'max:500'],
            'publisher'        => ['nullable', 'string', 'max:200'],
            'publication_year' => ['nullable', 'integer', 'min:1450', 'max:' . (date('Y') + 1)],
            'edition'          => ['nullable', 'string', 'max:50'],
            'language'         => ['nullable', 'string', 'size:2'],
            'pages'            => ['nullable', 'integer', 'min:1', 'max:50000'],
            'dewey_code'       => ['nullable', 'string', 'max:20'],
            'location_hint'    => ['nullable', 'string', 'max:100'],

            // ── Tipo de libro ─────────────────────────────────────────────────
            'is_digital'       => ['required', 'boolean'],

            // digital_file_url es obligatorio si is_digital = true,
            // y debe ser NULL si is_digital = false
            'digital_file_url' => [
                Rule::requiredIf(fn() => (bool) $this->is_digital),
                'nullable',
                'url',
                'max:500',
            ],

            // ── Relaciones ────────────────────────────────────────────────────
            'authors'          => ['required', 'array', 'min:1'],
            'authors.*.id'     => ['required', 'string', 'exists:authors,id'],
            'authors.*.role'   => ['required', Rule::in(['primary', 'coauthor', 'translator', 'editor'])],

            'categories'       => ['nullable', 'array'],
            'categories.*'     => ['string', 'exists:categories,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Un libro físico NO puede tener digital_file_url
            if (!$this->boolean('is_digital') && $this->filled('digital_file_url')) {
                $v->errors()->add(
                    'digital_file_url',
                    'Un libro físico no puede tener URL de archivo digital.'
                );
            }

            // Debe haber exactamente un autor con rol "primary"
            $authors   = $this->input('authors', []);
            $primaries = array_filter($authors, fn($a) => ($a['role'] ?? '') === 'primary');

            if (count($primaries) !== 1) {
                $v->errors()->add(
                    'authors',
                    'Debe especificarse exactamente un autor con rol "primary".'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'isbn_13.size'           => 'El ISBN-13 debe tener exactamente 13 caracteres.',
            'isbn_13.unique'         => 'Este ISBN-13 ya está registrado en el sistema.',
            'isbn_10.size'           => 'El ISBN-10 debe tener exactamente 10 caracteres.',
            'digital_file_url.required_if' => 'La URL del archivo es obligatoria para e-books.',
            'authors.required'       => 'El libro debe tener al menos un autor.',
            'authors.*.id.exists'    => 'Uno de los autores especificados no existe.',
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