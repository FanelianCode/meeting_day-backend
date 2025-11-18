<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property integer $id_votacion
 * @property integer $id_evento
 * @property integer $id_user
 * @property integer $id_gps
 */
class Votaciones extends Model
{
    protected $table = 'votaciones';
    /**
     * The primary key for the model.
     * 
     * @var string
     */
    protected $primaryKey = 'id_votacion';
    public $timestamps = false;

    /**
     * @var array
     */
    protected $fillable = ['id_evento', 'id_user', 'id_gps'];
}
