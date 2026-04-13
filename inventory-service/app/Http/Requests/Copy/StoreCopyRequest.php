<?php

namespace App\Http\Requests\Copy;

use App\Models\Book;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreCopyRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'condition'        => ['required', 'in:new,good,worn,damaged'],
            'location'         => ['nullable', 'string', 'max:30'],
            'is_loanable'      => ['nullable', 'boolean'],
            'acquired_at'      => ['nullable', 'date', 'before_or_equal:today'],
            'acquisition_cost' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'internal_notes'   => ['nullable', 'string', 'max:500'],
            // Permite registrar N copias idénticas en una sola operación
            'quantity'         => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Regla de negocio crítica: no crear copias de e-books
            $bookId = $this->route('bookId');
            $book   = Book::find($bookId);

            if (!$book) {
                $v->errors()->add('book', 'El libro especificado no existe.');
                return;
            }

            if ($book->is_digital) {
                $v->errors()->add(
                    'book',
                    "El libro \"{$book->title}\" es un e-book. " .
                    "Los e-books tienen licencia ilimitada y no requieren ejemplares físicos."
                );
            }
        });
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