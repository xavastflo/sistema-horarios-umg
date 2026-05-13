<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstadoHorario extends Model
{
    protected $table      = 'estado_horario';
    protected $primaryKey = 'id_estado_horario';
    public    $timestamps = false;

    // SQL oficial: tinyint(3) UNSIGNED
    protected $keyType = 'int';

    protected $fillable = [
        'nombre_estado',
        'descripcion',
        'estado',
    ];

    // Constantes para uso en código de negocio
    const BORRADOR  = 'borrador';
    const GENERADO  = 'generado';
    const APROBADO  = 'aprobado';
    const BLOQUEADO = 'bloqueado';
    const PUBLICADO = 'publicado';

    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }
}
