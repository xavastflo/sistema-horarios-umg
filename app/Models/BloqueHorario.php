<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BloqueHorario extends Model
{
    protected $table      = 'bloque_horario';
    protected $primaryKey = 'id_bloque_horario';
    public    $timestamps = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id_carrera_jornada',
        'id_dia',
        'hora_inicio',
        'hora_fin',
        'duracion_minutos',
        'estado',
    ];

    protected $casts = [
        'duracion_minutos'    => 'integer',
        'fecha_creacion'      => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function carreraJornada(): BelongsTo
    {
        return $this->belongsTo(CarreraJornada::class, 'id_carrera_jornada', 'id_carrera_jornada');
    }

    public function dia(): BelongsTo
    {
        return $this->belongsTo(Dia::class, 'id_dia', 'id_dia');
    }

    public function disponibilidades(): HasMany
    {
        return $this->hasMany(DisponibilidadDocente::class, 'id_bloque_horario', 'id_bloque_horario');
    }

    // ── Helpers ──────────────────────────────────────────────
    public function etiqueta(): string
    {
        return "{$this->hora_inicio} - {$this->hora_fin}";
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }

    public function scopePorDia($query, int $idDia)
    {
        return $query->where('id_dia', $idDia);
    }

    public function scopePorCarreraJornada($query, int $idCarreraJornada)
    {
        return $query->where('id_carrera_jornada', $idCarreraJornada);
    }
}
