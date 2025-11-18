<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property integer $id_miembro
 * @property integer $id_grupo
 * @property integer $id_user
 * @property integer $confirm
 */
class Miembro extends Model
{
    protected $table = 'miembros';
    /**
     * The primary key for the model.
     * 
     * @var string
     */
    protected $primaryKey = 'id_miembro';
    public $timestamps = false;

    /**
     * @var array
     */
    protected $fillable = ['id_grupo', 'id_user', 'confirm'];
}
