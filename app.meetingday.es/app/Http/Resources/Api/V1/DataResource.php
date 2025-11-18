<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class DataResource extends JsonResource
{
    public function toArray($request): array
    {
        // $this es una instancia de App\Models\Data
        return [
            'id_data'        => $this->id_data,
            'nombre'         => $this->nombre,
            'apellido'       => $this->apellido,
            'nick'           => $this->nick,
            'img_profile'    => $this->img_profile, // nombre del archivo (default.png o *.webp)
            'img_profile_url'=> $this->img_profile
                ? url('/api/v1/media/img_profiles/' . $this->img_profile)
                : null,
            'method'         => $this->method,
            'indicativo'     => $this->indicativo,
            'number'         => $this->number,
            'mail'           => $this->mail,
            'token_movil'    => $this->token_movil,
        ];
    }
}
