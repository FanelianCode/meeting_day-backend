<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invitado extends Model
{
    protected $table = 'invitados';
    protected $primaryKey = 'id_invi';
    public $timestamps = false;

    protected $fillable = ['id_evento', 'id_user', 'confirm'];
    
    
    public function usuario()
    {
        return $this->belongsTo(Data::class, 'id_user', 'id_data');
    }


    public function evento()
    {
        return $this->belongsTo(Evento::class, 'id_evento', 'id_evento');
    }
}
