<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Facultad extends Model
{
    protected $table      = 'facultad';
    protected $primaryKey = 'id_facultad';
    public    $timestamps = false;

    // SQL oficial: smallint(5) UNSIGNED
    protected $keyType = 'int';

    protected $fillable = [
        'nombre_facultad',
        'codigo_facultad',
        'descripcion',
        'estado',
    ];

    protected $casts = [
        'fecha_creacion'      => 'datetime',
        'fecha_actualizacion' => 'datetime',
        // estado es ENUM string
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function carreras(): HasMany
    {
        return $this->hasMany(Carrera::class, 'id_facultad', 'id_facultad');
    }

    public function carrerasActivas(): HasMany
    {
        return $this->carreras()->where('estado', 'activo');
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }
}
