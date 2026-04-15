<?php

namespace App\Http\Requests\Copy;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateCopyConditionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'status'    => ['sometimes', 'in:available,in_repair,withdrawn'],
            'condition' => ['sometimes', 'in:new,good,worn,damaged,lost'],
            'notes'     => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Al menos uno de los dos campos debe estar presente
            if (!$this->has('status') && !$this->has('condition')) {
                $v->errors()->add(
                    'status',
                    'Debe especificar al menos "status" o "condition" para actualizar.'
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