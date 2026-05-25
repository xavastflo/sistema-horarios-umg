<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Curso extends Model
{
    protected $table      = 'curso';
    protected $primaryKey = 'id_curso';
    public    $timestamps = false;

    protected $keyType = 'int';

    protected $fillable = [
        'codigo_curso',
        'nombre_curso',
        'estado',
    ];

    protected $casts = [
        'fecha_creacion'      => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function pensums(): BelongsToMany
    {
        return $this->belongsToMany(
            Pensum::class,
            'pensum_curso',
            'id_curso',
            'id_pensum'
        )->withPivot(['id_pensum_curso', 'ciclo_semestre', 'estado', 'fecha_creacion']);
    }

    public function secciones(): HasMany
    {
        return $this->hasMany(Seccion::class, 'id_curso', 'id_curso');
    }

    public function pensumCursos(): HasMany
    {
        return $this->hasMany(\App\Models\PensumCurso::class, 'id_curso', 'id_curso');
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }
}
