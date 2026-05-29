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
        'anio_inicio_vigencia',
        'anio_fin_vigencia',
        'nombre_pensum',
        'codigo_pensum',
        'descripcion',
        'estado',
    ];

    protected $casts = [
        'anio_inicio_vigencia' => 'integer',
        'anio_fin_vigencia'    => 'integer',
        'fecha_creacion'       => 'datetime',
        'fecha_actualizacion'  => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────

    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class, 'id_carrera', 'id_carrera');
    }

    // periodoAcademico() eliminada: pensum ya no tiene id_periodo_academico.

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

    // ── Helper de pensum vigente ──────────────────────────────

    /**
     * Devuelve el id_pensum activo vigente para una carrera en un año dado.
     *
     * Ordena por anio_inicio_vigencia DESC para que, si más de un pensum activo
     * cubre el año consultado, se tome el más reciente.
     *
     * Ejemplo:
     *   Pensum 2014 → anio_inicio=2014, anio_fin=NULL
     *   Pensum 2028 → anio_inicio=2028, anio_fin=NULL
     *   Para anio=2030 → toma Pensum 2028.
     *   Para anio=2026 → toma Pensum 2014.
     */
    public static function idVigente(int $idCarrera, int $anio): ?int
    {
        return static::where('id_carrera', $idCarrera)
            ->where('estado', 'activo')
            ->where('anio_inicio_vigencia', '<=', $anio)
            ->where(function ($q) use ($anio) {
                $q->whereNull('anio_fin_vigencia')
                  ->orWhere('anio_fin_vigencia', '>=', $anio);
            })
            ->orderBy('anio_inicio_vigencia', 'desc')
            ->value('id_pensum');
    }
}
