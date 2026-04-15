<?php

namespace App\Http\Requests\Loan;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            // Datos del libro (obtenidos desde inventory-service antes de llamar aquí)
            'book.id'         => ['required', 'uuid'],
            'book.title'      => ['required', 'string', 'max:255'],
            'book.isbn'       => ['nullable', 'string'],
            'book.author'     => ['nullable', 'string'],
            'book.is_digital' => ['required', 'boolean'],

            // Solo requerido para libros físicos
            'book.copy_id'    => ['required_if:book.is_digital,false', 'nullable', 'uuid'],
            'book.copy_code'  => ['nullable', 'string'],
            'book.condition'  => ['required_if:book.is_digital,false', 'nullable', 'in:new,good,worn,damaged'],

            // Datos del lector
            'user.id'         => ['required', 'uuid'],
            'user.name'       => ['required', 'string', 'max:150'],
            'user.email'      => ['required', 'email'],
        ];
    }

    public function messages(): array
    {
        return [
            'book.copy_id.required_if'  => 'El ejemplar físico es obligatorio para préstamos de libros físicos.',
            'book.condition.required_if' => 'La condición del ejemplar es obligatoria al registrar el préstamo.',
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