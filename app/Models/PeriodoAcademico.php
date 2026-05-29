<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeriodoAcademico extends Model
{
    protected $table      = 'periodo_academico';
    protected $primaryKey = 'id_periodo_academico';
    public    $timestamps = false;

    // SQL oficial: int(10) UNSIGNED
    protected $keyType = 'int';

    // Estados del ENUM del SQL oficial — distinto al de otras tablas
    const ESTADO_PLANIFICACION = 'planificacion';
    const ESTADO_ACTIVO        = 'activo';
    const ESTADO_CERRADO       = 'cerrado';
    const ESTADO_FINALIZADO    = 'finalizado';

    const ESTADOS = ['planificacion', 'activo', 'cerrado', 'finalizado'];

    protected $fillable = [
        'nombre_periodo',
        'anio',
        'numero_periodo',
        'fecha_inicio',
        'fecha_fin',
        'fecha_limite_edicion_horarios',
        'estado',
        'es_vigente',
    ];

    protected $casts = [
        'anio'                          => 'integer',
        'numero_periodo'                => 'integer',
        'fecha_inicio'                  => 'date',
        'fecha_fin'                     => 'date',
        'fecha_limite_edicion_horarios' => 'datetime',
        'es_vigente'                    => 'boolean',
        'fecha_creacion'                => 'datetime',
        'fecha_actualizacion'           => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function pensums(): HasMany
    {
        return $this->hasMany(Pensum::class, 'id_periodo_academico', 'id_periodo_academico');
    }

    public function secciones(): HasMany
    {
        return $this->hasMany(Seccion::class, 'id_periodo_academico', 'id_periodo_academico');
    }

    // ── Helpers ──────────────────────────────────────────────
    /**
     * Verifica si el horario aún puede editarse según la fecha límite.
     */
    public function estaEnPlazoEdicion(): bool
    {
        if (! $this->fecha_limite_edicion_horarios) {
            return true; // Sin límite definido: editable
        }
        return now()->lessThanOrEqualTo($this->fecha_limite_edicion_horarios);
    }

    public function estaActivo(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }

    public function esPeriodoImpar(): bool
    {
        return (int) $this->numero_periodo === 1;
    }

    public function esPeriodoPar(): bool
    {
        return (int) $this->numero_periodo === 2;
    }

    public function ciclosPermitidos(): array
    {
        return $this->esPeriodoImpar()
            ? [1, 3, 5, 7, 9, 11]
            : [2, 4, 6, 8, 10, 12];
    }
    
    // ── Scopes ───────────────────────────────────────────────
    public function scopeVigente($query)
    {
        return $query->where('es_vigente', true);
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }

    public function scopeEnPlanificacion($query)
    {
        return $query->where('estado', 'planificacion');
    }
}
