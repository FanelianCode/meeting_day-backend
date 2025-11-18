<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property integer $id_evento
 * @property integer $id_user
 * @property integer $estado
 * @property string $titulo
 * @property string $descripcion
 * @property integer $tipo
 * @property integer $meeting
 * @property integer $confirm
 * @property string $flimit
 * @property string $hlimit
 * @property string $time_zone
 */
class Evento extends Model
{
    protected $table = 'eventos';
    /**
     * The primary key for the model.
     * 
     * @var string
     */
    protected $primaryKey = 'id_evento';
    public $timestamps = false;

    /**
     * @var array
     */
    protected $fillable = ['id_user', 'estado', 'titulo', 'descripcion', 'tipo', 'meeting', 'confirm', 'flimit', 'hlimit', 'time_zone'];
    
    // Relaciones
    public function creador()
    {
        return $this->belongsTo(Data::class, 'id_user', 'id_data');
    }
    
    public function imagenes()
    {
        return $this->hasMany(ImgEvento::class, 'id_evento', 'id_evento');
    }

    public function gps()
    {
        return $this->hasMany(Gps::class, 'id_evento', 'id_evento');
    }

    public function invitados()
    {
        return $this->hasMany(Invitado::class, 'id_evento', 'id_evento');
    }

    public function votaciones()
    {
        return $this->hasMany(Votaciones::class, 'id_evento', 'id_evento');
    }
}
