<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Carrera extends Model
{
    protected $table      = 'carrera';
    protected $primaryKey = 'id_carrera';
    public    $timestamps = false;

    // SQL oficial: int(10) UNSIGNED
    protected $keyType = 'int';

    protected $fillable = [
        'id_facultad',
        'nombre_carrera',
        'codigo_carrera',
        'id_usuario_coordinador',
        'estado',
        'fecha_asignacion_coordinador',
        'fecha_desasignacion_coordinador',
    ];

    protected $casts = [
        'fecha_asignacion_coordinador'    => 'datetime',
        'fecha_desasignacion_coordinador' => 'datetime',
        'fecha_creacion'                  => 'datetime',
        'fecha_actualizacion'             => 'datetime',
        // estado es ENUM string
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function facultad(): BelongsTo
    {
        return $this->belongsTo(Facultad::class, 'id_facultad', 'id_facultad');
    }

    public function coordinador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_coordinador', 'id_usuario');
    }

    public function jornadas(): BelongsToMany
    {
        return $this->belongsToMany(
            Jornada::class,
            'carrera_jornada',
            'id_carrera',
            'id_jornada'
        )->withPivot(['id_carrera_jornada', 'estado', 'fecha_creacion']);
    }

    public function jornadasActivas(): BelongsToMany
    {
        return $this->jornadas()->wherePivot('estado', 'activo');
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }
}
