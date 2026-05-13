<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pensum extends Model
{
    protected $table      = 'pensum';
    protected $primaryKey = 'id_pensum';
    public    $timestamps = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id_carrera',
        'id_periodo_academico',
        'nombre_pensum',
        'codigo_pensum',
        'descripcion',
        'estado',
    ];

    protected $casts = [
        'fecha_creacion'      => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class, 'id_carrera', 'id_carrera');
    }

    public function periodoAcademico(): BelongsTo
    {
        return $this->belongsTo(PeriodoAcademico::class, 'id_periodo_academico', 'id_periodo_academico');
    }

    public function cursos(): BelongsToMany
    {
        return $this->belongsToMany(
            Curso::class,
            'pensum_curso',
            'id_pensum',
            'id_curso'
        )->withPivot(['id_pensum_curso', 'ciclo_semestre', 'estado', 'fecha_creacion'])
         ->wherePivot('estado', 'activo');
    }

    public function todosCursos(): BelongsToMany
    {
        return $this->belongsToMany(
            Curso::class,
            'pensum_curso',
            'id_pensum',
            'id_curso'
        )->withPivot(['id_pensum_curso', 'ciclo_semestre', 'estado', 'fecha_creacion']);
    }

    public function pensumCursos(): HasMany
    {
        return $this->hasMany(PensumCurso::class, 'id_pensum', 'id_pensum');
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }
}
