<?php

namespace App\Http\Requests\Api\V1\Evento;

use Illuminate\Foundation\Http\FormRequest;

class InvitadosSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ajusta si usas auth
    }

    public function rules(): array
    {
        return [
            'id_evento'   => ['required','integer','exists:eventos,id_evento'],
            'invitados'   => ['required','array'],
            'invitados.*' => ['nullable','string','regex:/^\+\d{8,15}$/'], // teléfonos en string
        ];
    }
}
