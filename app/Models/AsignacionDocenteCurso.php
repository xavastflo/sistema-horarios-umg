<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsignacionDocenteCurso extends Model
{
    protected $table      = 'asignacion_docente_curso';
    protected $primaryKey = 'id_asignacion_docente_curso';
    public    $timestamps = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id_docente',
        'id_seccion',
        'estado',
    ];

    protected $casts = [
        'fecha_asignacion'    => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function docente(): BelongsTo
    {
        return $this->belongsTo(Docente::class, 'id_docente', 'id_docente');
    }

    public function seccion(): BelongsTo
    {
        return $this->belongsTo(Seccion::class, 'id_seccion', 'id_seccion');
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

    public function scopeDelPeriodo($query, int $idPeriodo)
    {
        return $query->whereHas(
            'seccion',
            fn($q) => $q->where('id_periodo_academico', $idPeriodo)
        );
    }
}
