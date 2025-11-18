<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property integer $id_data
 * @property string $nombre
 * @property string $apellido
 * @property string $nick
 * @property string $img_profile
 * @property integer $method
 * @property string $indicativo
 * @property string $number
 * @property string $mail
 * @property string $token_movil
 */
class Data extends Model
{
    /**
     * The primary key for the model.
     * 
     * @var string
     */
    protected $primaryKey = 'id_data';
    public $timestamps = false;

    /**
     * @var array
     */
    protected $fillable = ['nombre', 'apellido', 'nick', 'img_profile', 'method', 'indicativo', 'number', 'mail', 'token_movil'];
}
