<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property integer $id_cancel
 * @property integer $id_evento
 * @property string $motivo
 */
class Cancelaciones extends Model
{
    protected $table = 'cancelaciones';
    /**
     * The primary key for the model.
     * 
     * @var string
     */
    protected $primaryKey = 'id_cancel';
    public $timestamps = false;

    /**
     * @var array
     */
    protected $fillable = ['id_evento', 'motivo'];
}
