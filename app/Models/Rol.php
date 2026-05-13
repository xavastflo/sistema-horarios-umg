<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Rol extends Model
{
    protected $table      = 'rol';
    protected $primaryKey = 'id_rol';
    public    $timestamps = false;

    // SQL oficial: tinyint(3) UNSIGNED — no bigint
    protected $keyType    = 'int';

    // Valores ENUM del SQL oficial
    const ESTADO_ACTIVO   = 'activo';
    const ESTADO_INACTIVO = 'inactivo';

    protected $fillable = [
        'nombre_rol',
        'descripcion',
        'estado',
    ];

    protected $casts = [
        'fecha_creacion' => 'datetime',
        // estado es string ENUM — sin cast a integer
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(
            Usuario::class,
            'usuario_rol',
            'id_rol',
            'id_usuario'
        )->withPivot(['estado', 'fecha_asignacion', 'fecha_desasignacion'])
         ->wherePivot('estado', 'activo');      // ENUM string, no 1
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }
}
