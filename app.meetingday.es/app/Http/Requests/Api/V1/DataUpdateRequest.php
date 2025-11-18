<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class DataUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ajusta si usas políticas
    }

    public function rules(): array
    {
        return [
            // Acepta id o id_data (uno de los dos es requerido)
            'id'       => ['nullable','integer','required_without:id_data'],
            'id_data'  => ['nullable','integer','required_without:id'],

            // Campos actualizables
            'nombre'      => ['sometimes','string','max:150'],
            'apellido'    => ['sometimes','string','max:150'],

            // Puede venir base64/dataURL o un filename existente (incluye default.png)
            'img_profile' => ['sometimes','string'],
        ];
    }
}
