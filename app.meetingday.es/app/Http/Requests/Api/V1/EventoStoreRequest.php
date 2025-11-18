<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class EventoStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // luego se ajusta con auth
    }

    public function rules(): array
    {
        return [
            'idU'       => 'required|string',
            'title'     => 'required|string',
            'descrip'   => 'required|string',
            'type'      => 'required',
            'limitf'    => 'required|string',
            'limith'    => 'required|string',
            'contacts'  => 'nullable',
            'timezone'  => 'required|string',
            // type 1
            'lugar'     => 'required_if:type,1|string',
            'gps'       => 'required_if:type,1|string',
            'fecha'     => 'required_if:type,1|string',
            'hora'      => 'required_if:type,1|string',
            // type 2
            'placesData' => 'nullable',
            'file.*'     => 'nullable|file|mimes:jpg,jpeg,png,gif'
        ];
    }
}
