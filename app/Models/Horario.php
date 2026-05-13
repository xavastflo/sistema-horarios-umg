<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo Horario.
 *
 * La tabla 'horario' está definida en el SQL oficial (Sprint 2 del modelo).
 * Su migración se genera en el paso de Persistencia Final (Paso 6 del Sprint 3).
 * Este modelo se crea ahora porque ConflictValidationService lo necesita
 * para la validación de estado.
 *
 * Estados (via estado_horario.nombre_estado):
 *   borrador  → editable
 *   generado  → editable
 *   aprobado  → NO editable
 *   bloqueado → NO editable
 *   publicado → NO editable
 */
class Horario extends Model
{
    protected $table      = 'horario';
    protected $primaryKey = 'id_horario';
    public    $timestamps = false;

    // SQL oficial: int(10) UNSIGNED
    protected $keyType = 'int';

    protected $fillable = [
        'id_carrera',
        'id_periodo_academico',
        'id_estado_horario',
        'version_horario',
        'fecha_generacion',
        'fecha_aprobacion',
        'fecha_bloqueo',
        'observaciones',
    ];

    protected $casts = [
        'version_horario'     => 'integer',
        'fecha_generacion'    => 'datetime',
        'fecha_aprobacion'    => 'datetime',
        'fecha_bloqueo'       => 'datetime',
        'fecha_creacion'      => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────────
    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class, 'id_carrera', 'id_carrera');
    }

    public function periodoAcademico(): BelongsTo
    {
        return $this->belongsTo(PeriodoAcademico::class, 'id_periodo_academico', 'id_periodo_academico');
    }

    public function estadoHorario(): BelongsTo
    {
        return $this->belongsTo(EstadoHorario::class, 'id_estado_horario', 'id_estado_horario');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleHorario::class, 'id_horario', 'id_horario');
    }

    public function detallesActivos(): HasMany
    {
        return $this->detalles()->where('estado', 'activo');
    }

    // ── Helpers ─────────────────────────────────────────────────
    public function esEditable(): bool
    {
        $nombreEstado = $this->estadoHorario?->nombre_estado;
        return in_array($nombreEstado, ['borrador', 'generado'], true);
    }

    // ── Scopes ──────────────────────────────────────────────────
    public function scopePorCarreraYPeriodo($query, int $idCarrera, int $idPeriodo)
    {
        return $query->where('id_carrera', $idCarrera)
                     ->where('id_periodo_academico', $idPeriodo);
    }
}
