<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Docente extends Model
{
    protected $table      = 'docente';
    protected $primaryKey = 'id_docente';
    public    $timestamps = false;

    // SQL oficial: int(10) UNSIGNED
    protected $keyType = 'int';

    const PRIORIDAD_ALTA    = 1;
    const PRIORIDAD_MEDIA   = 2;
    const PRIORIDAD_BAJA    = 3;
    const PRIORIDADES_VALIDAS = [1, 2, 3];
    const PRIORIDAD_DEFAULT = 3;  // SQL oficial: DEFAULT 3

    protected $fillable = [
        'id_usuario',
        'codigo_docente',
        'prioridad',
        'estado',
    ];

    protected $casts = [
        'prioridad'           => 'integer',
        'fecha_creacion'      => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }

    public function disponibilidades(): HasMany
    {
        return $this->hasMany(DisponibilidadDocente::class, 'id_docente', 'id_docente');
    }

    public function disponibilidadesActivas(): HasMany
    {
        return $this->disponibilidades()->where('estado', 'activo');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(AsignacionDocenteCurso::class, 'id_docente', 'id_docente');
    }

    public function asignacionesActivas(): HasMany
    {
        return $this->asignaciones()->where('estado', 'activo');
    }

    /**
     * IDs de bloques donde el docente NO puede dar clase.
     * Un registro en disponibilidad_docente = bloqueo.
     */
    public function bloquesNoDisponibles(): Collection
    {
        return $this->disponibilidadesActivas()->pluck('id_bloque_horario');
    }

    // ── Helpers ──────────────────────────────────────────────
    public function etiquetaPrioridad(): string
    {
        return match ($this->prioridad) {
            1 => 'Alta',
            2 => 'Media',
            3 => 'Baja',
            default => 'No definida',
        };
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }

    /** Ordena ASC: prioridad 1 (alta) aparece primero */
    public function scopeOrdenadosPorPrioridad($query)
    {
        return $query->orderBy('prioridad', 'asc');
    }
}
