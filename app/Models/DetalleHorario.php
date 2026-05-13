<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleHorario extends Model
{
    protected $table      = 'detalle_horario';
    protected $primaryKey = 'id_detalle_horario';
    public    $timestamps = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id_horario',
        'id_asignacion_docente_curso',
        'id_dia',
        'id_bloque_horario',
        'estado',
    ];

    protected $casts = [
        'fecha_creacion'      => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────────
    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class, 'id_horario', 'id_horario');
    }

    public function asignacionDocenteCurso(): BelongsTo
    {
        return $this->belongsTo(AsignacionDocenteCurso::class, 'id_asignacion_docente_curso', 'id_asignacion_docente_curso');
    }

    public function dia(): BelongsTo
    {
        return $this->belongsTo(Dia::class, 'id_dia', 'id_dia');
    }

    public function bloqueHorario(): BelongsTo
    {
        return $this->belongsTo(BloqueHorario::class, 'id_bloque_horario', 'id_bloque_horario');
    }

    // ── Scopes ──────────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }
}
