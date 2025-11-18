<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class DataStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 🚧 luego se ata a auth/policies si hace falta
    }

    public function rules(): array
    {
        return [
            'nombre'      => ['required','string','max:150'],
            'apellido'    => ['required','string','max:150'],
            'nick'        => ['required','string','max:100','unique:data,nick'],
            // img_profile llega en base64 (opcional). La convertimos a .webp en el controlador
            'img_profile' => ['nullable','string'],
            'met'         => ['required','integer'],          // → method
            'indi'        => ['nullable','string','max:10'],  // → indicativo
            'num'         => ['required','string','max:20'],  // → number
            'mail'        => ['required','email','max:150','unique:data,mail'],
            'token'       => ['nullable','string','max:255'], // → token_movil
        ];
    }
}
