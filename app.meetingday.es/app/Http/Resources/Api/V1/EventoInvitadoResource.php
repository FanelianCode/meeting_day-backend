<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventoInvitadoResource extends JsonResource
{
    /**
     * Espera un stdClass/array con los campos ya preparados en el Controller.
     */
    public function toArray(Request $request): array
    {
        return [
            'id_evento'   => $this->id_evento,
            'id_user'     => $this->id_user,      // creador del evento
            'titulo'      => $this->titulo,
            'descripcion' => $this->descripcion,
            'tipo'        => $this->tipo,
            'meeting'     => $this->meeting,
            'confirm'     => $this->confirm,      // confirm del evento si lo usas
            'flimit'      => $this->flimit,
            'hlimit'      => $this->hlimit,
            'time_zone'   => $this->time_zone,
            'estado'      => $this->estado,

            'creador'     => $this->creador ?? 'Usuario no encontrado',

            // GPS y selección
            'eleccion'    => $this->eleccion ?? 0,
            'eleccionId'  => $this->eleccionId ?? null,
            'fecha'       => $this->fecha ?? 'Por definir',
            'lugar'       => $this->lugar ?? 'Por definir',
            'hora'        => $this->hora ?? 'Por definir',
            'gps_details' => $this->gps_details ?? [],
            'locations'   => $this->locations ?? [],

            // Imagen
            'imagen'      => $this->imagen ?? 'default.png',

            // Datos de la invitación
            'confirmacion'=> $this->confirmacion, // confirm del invitado (invitados.confirm)
            'invitado'    => $this->invitado,     // id_user (invitado)
        ];
    }
}
