<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsuarioRol extends Model
{
    protected $table      = 'usuario_rol';
    protected $primaryKey = 'id_usuario_rol';
    public    $timestamps = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id_usuario',
        'id_rol',
        'estado',
        'fecha_asignacion',
        'fecha_desasignacion',
    ];

    protected $casts = [
        'fecha_asignacion'    => 'datetime',
        'fecha_desasignacion' => 'datetime',
        // estado es ENUM string — sin cast a integer
    ];

    // ── Relaciones ──────────────────────────────────────────
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'id_rol', 'id_rol');
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }
}
