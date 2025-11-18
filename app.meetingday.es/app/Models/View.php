<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property integer $id_view
 * @property integer $id_notif
 * @property integer $id_user
 */
class View extends Model
{
    protected $table = 'views';
    /**
     * The primary key for the model.
     * 
     * @var string
     */
    protected $primaryKey = 'id_view';
    public $timestamps = false;

    /**
     * @var array
     */
    protected $fillable = ['id_notif', 'id_user'];
}
