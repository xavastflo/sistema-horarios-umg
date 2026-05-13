<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dia extends Model
{
    protected $table      = 'dia';
    protected $primaryKey = 'id_dia';
    public    $timestamps = false;

    // SQL oficial: tinyint(3) UNSIGNED, PK fija 1-7
    protected $keyType      = 'int';
    public    $incrementing = false;  // IDs fijos — no autoincrement

    protected $fillable = [
        'id_dia',
        'nombre_dia',
        'orden_semana',
        'estado',
    ];

    protected $casts = [
        'id_dia'       => 'integer',
        'orden_semana' => 'integer',
        // estado es ENUM string
    ];

    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }
}
