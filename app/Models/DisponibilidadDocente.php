<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisponibilidadDocente extends Model
{
    protected $table      = 'disponibilidad_docente';
    protected $primaryKey = 'id_disponibilidad_docente';
    public    $timestamps = false;

    protected $keyType = 'int';

    // REGLA: Si existe un registro, el docente NO está disponible en ese bloque.
    // Si no existe, el docente SÍ está disponible.
    // No hay campo tipo_disponibilidad — el SQL oficial no lo tiene.

    protected $fillable = [
        'id_docente',
        'id_bloque_horario',
        'observacion',
        'estado',
    ];

    protected $casts = [
        'fecha_registro'      => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function docente(): BelongsTo
    {
        return $this->belongsTo(Docente::class, 'id_docente', 'id_docente');
    }

    public function bloqueHorario(): BelongsTo
    {
        return $this->belongsTo(BloqueHorario::class, 'id_bloque_horario', 'id_bloque_horario');
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }

    public function scopeDelDocente($query, int $idDocente)
    {
        return $query->where('id_docente', $idDocente);
    }
}
