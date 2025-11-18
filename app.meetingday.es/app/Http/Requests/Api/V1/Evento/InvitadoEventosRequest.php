<?php

namespace App\Http\Requests\Api\V1\Evento;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class InvitadoEventosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Mapea ?user=... o body.user a 'nick' antes de validar */
    protected function prepareForValidation(): void
    {
        $nick = $this->query('user') ?? $this->input('user') ?? $this->input('nick');
        if ($nick !== null) {
            $this->merge(['nick' => $nick]);
        }
    }

    public function rules(): array
    {
        return [
            'nick' => ['required','string','max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'nick.required' => 'El nick del usuario es obligatorio.',
        ];
    }

    /** Evita redirect y devuelve JSON 422 en APIs */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
