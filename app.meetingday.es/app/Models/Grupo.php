<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property integer $id_grupo
 * @property integer $id_user
 * @property string $nombre
 * @property string $img_grupo
 */
class Grupo extends Model
{
    protected $table = 'grupos';
    /**
     * The primary key for the model.
     * 
     * @var string
     */
    protected $primaryKey = 'id_grupo';
    public $timestamps = false;

    /**
     * @var array
     */
    protected $fillable = ['id_user', 'nombre', 'img_grupo'];
}
