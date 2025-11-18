<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gps extends Model
{
    protected $table = 'gps';
    protected $primaryKey = 'id_gps';
    public $timestamps = false;

    protected $fillable = ['id_evento', 'location', 'place', 'fecha', 'hora', 'marker'];

    public function evento()
    {
        return $this->belongsTo(Evento::class, 'id_evento', 'id_evento');
    }
}
