<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notificacion extends Model
{
    protected $table      = 'notificacion';
    protected $primaryKey = 'id_notificacion';
    public    $timestamps = false;

    // SQL oficial: int(10) UNSIGNED
    protected $keyType = 'int';

    // Tipos del ENUM oficial — única fuente de verdad
    const TIPO_CAMBIO_HORARIO    = 'cambio_horario';
    const TIPO_BLOQUEO_HORARIO   = 'bloqueo_horario';
    const TIPO_APROBACION        = 'aprobacion_horario';
    const TIPO_GENERAL           = 'general';

    const TIPOS_VALIDOS = [
        self::TIPO_CAMBIO_HORARIO,
        self::TIPO_BLOQUEO_HORARIO,
        self::TIPO_APROBACION,
        self::TIPO_GENERAL,
    ];

    protected $fillable = [
        'id_usuario',
        'titulo',
        'mensaje',
        'tipo_notificacion',
        'leida',
        'fecha_envio',
        'fecha_lectura',
        'estado',
    ];

    protected $casts = [
        'leida'         => 'boolean',
        'fecha_envio'   => 'datetime',
        'fecha_lectura' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────────────
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }

    // ── Scopes ───────────────────────────────────────────────────
    public function scopeActivas($query)
    {
        return $query->where('estado', 'activo');
    }

    public function scopeNoLeidas($query)
    {
        return $query->where('leida', false)->where('estado', 'activo');
    }

    public function scopeDelUsuario($query, int $idUsuario)
    {
        return $query->where('id_usuario', $idUsuario);
    }
}
