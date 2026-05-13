<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarreraJornada extends Model
{
    protected $table      = 'carrera_jornada';
    protected $primaryKey = 'id_carrera_jornada';
    public    $timestamps = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id_carrera',
        'id_jornada',
        'estado',
    ];

    protected $casts = [
        'fecha_creacion' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class, 'id_carrera', 'id_carrera');
    }

    public function jornada(): BelongsTo
    {
        return $this->belongsTo(Jornada::class, 'id_jornada', 'id_jornada');
    }

    public function bloquesHorario(): HasMany
    {
        return $this->hasMany(BloqueHorario::class, 'id_carrera_jornada', 'id_carrera_jornada');
    }

    public function bloquesActivos(): HasMany
    {
        return $this->bloquesHorario()->where('estado', 'activo');
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }
}
