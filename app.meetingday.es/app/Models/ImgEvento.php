<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImgEvento extends Model
{
    protected $table = 'img_eventos';
    protected $primaryKey = 'id_img';
    public $timestamps = false;

    protected $fillable = ['id_evento', 'img_url'];

    public function evento()
    {
        return $this->belongsTo(Evento::class, 'id_evento', 'id_evento');
    }
}
