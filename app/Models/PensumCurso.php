<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PensumCurso extends Model
{
    protected $table      = 'pensum_curso';
    protected $primaryKey = 'id_pensum_curso';
    public    $timestamps = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id_pensum',
        'id_curso',
        'ciclo_semestre',
        'bloques_semanales',
        'estado',
    ];

    protected $casts = [
        'ciclo_semestre'    => 'integer',
        'bloques_semanales' => 'integer',
        'fecha_creacion'    => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function pensum(): BelongsTo
    {
        return $this->belongsTo(Pensum::class, 'id_pensum', 'id_pensum');
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class, 'id_curso', 'id_curso');
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }

    public function scopePorCiclo($query, int $ciclo)
    {
        return $query->where('ciclo_semestre', $ciclo);
    }
}
