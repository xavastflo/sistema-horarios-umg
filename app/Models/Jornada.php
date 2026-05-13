<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Jornada extends Model
{
    protected $table      = 'jornada';
    protected $primaryKey = 'id_jornada';
    public    $timestamps = false;

    // SQL oficial: tinyint(3) UNSIGNED
    protected $keyType = 'int';

    protected $fillable = [
        'nombre_jornada',
        'descripcion',
        'estado',
    ];

    protected $casts = [
        'fecha_creacion' => 'datetime',
        // estado es ENUM string
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function carreras(): BelongsToMany
    {
        return $this->belongsToMany(
            Carrera::class,
            'carrera_jornada',
            'id_jornada',
            'id_carrera'
        )->withPivot(['id_carrera_jornada', 'estado', 'fecha_creacion']);
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }
}
