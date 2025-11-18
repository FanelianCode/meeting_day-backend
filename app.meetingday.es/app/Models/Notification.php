<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property integer $id
 * @property boolean $type
 * @property boolean $type_user
 * @property integer $id_evento
 * @property integer $id_user
 * @property string $created_at
 * @property boolean $is_read
 */
class Notification extends Model
{
    
    protected $table = 'notifications';
    public $timestamps = false;
    /**
     * @var array
     */
    protected $fillable = ['type', 'type_user', 'id_evento', 'id_user', 'created_at', 'is_read'];
}
