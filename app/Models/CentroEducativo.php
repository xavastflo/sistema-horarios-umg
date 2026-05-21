<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentroEducativo extends Model
{
    protected $table      = 'centro_educativo';
    protected $primaryKey = 'id_centro_educativo';
    public    $timestamps = false;
    protected $keyType    = 'int';

    protected $fillable = [
        'nombre',
        'codigo_sede',
        'direccion',
        'estado',
    ];

    protected $casts = [
        'fecha_creacion'      => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function facultades(): HasMany
    {
        return $this->hasMany(Facultad::class, 'id_centro_educativo', 'id_centro_educativo');
    }

    public function facultadesActivas(): HasMany
    {
        return $this->facultades()->where('estado', 'activo');
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }
}
