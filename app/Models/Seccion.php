<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Seccion extends Model
{
    protected $table      = 'seccion';
    protected $primaryKey = 'id_seccion';
    public    $timestamps = false;
    protected $keyType    = 'int';

    protected $fillable = [
        'id_carrera_jornada',   // nueva FK obligatoria
        'id_curso',
        'id_periodo_academico',
        'numero_seccion',
        'estado',
    ];

    protected $casts = [
        'fecha_creacion'      => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────

    /** Nueva: jornada a la que pertenece la sección */
    public function carreraJornada(): BelongsTo
    {
        return $this->belongsTo(CarreraJornada::class, 'id_carrera_jornada', 'id_carrera_jornada');
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class, 'id_curso', 'id_curso');
    }

    public function periodoAcademico(): BelongsTo
    {
        return $this->belongsTo(PeriodoAcademico::class, 'id_periodo_academico', 'id_periodo_academico');
    }

    /**
     * Una sección tiene como máximo una asignación docente activa.
     * UNIQUE(id_seccion) en asignacion_docente_curso lo garantiza a nivel BD.
     */
    public function asignacion(): HasOne
    {
        return $this->hasOne(AsignacionDocenteCurso::class, 'id_seccion', 'id_seccion');
    }

    public function asignacionActiva(): HasOne
    {
        return $this->asignacion()->where('estado', 'activo');
    }

    // ── Helpers ──────────────────────────────────────────────
    public function tieneDocente(): bool
    {
        return $this->asignacionActiva()->exists();
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }

    public function scopePorPeriodo($query, int $idPeriodo)
    {
        return $query->where('id_periodo_academico', $idPeriodo);
    }

    public function scopePorJornada($query, int $idCarreraJornada)
    {
        return $query->where('id_carrera_jornada', $idCarreraJornada);
    }
}
