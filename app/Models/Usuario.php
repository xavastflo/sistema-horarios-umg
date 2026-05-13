<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens;

    protected $table      = 'usuario';
    protected $primaryKey = 'id_usuario';
    public    $timestamps = false;

    // SQL oficial: int(10) UNSIGNED — no bigint
    protected $keyType = 'int';

    // Valores ENUM del SQL oficial
    const ESTADO_ACTIVO   = 'activo';
    const ESTADO_INACTIVO = 'inactivo';
    const ESTADO_BLOQUEADO = 'bloqueado';

    protected $fillable = [
        'nombres',
        'apellidos',
        'nombre_usuario',
        'correo_electronico',
        'telefono',
        'password_hash',
        'pregunta_seguridad',         // NOT NULL en SQL oficial
        'respuesta_seguridad_hash',   // NOT NULL en SQL oficial
        'ultimo_perfil_activo',       // VARCHAR(100) — nombre del rol como texto
        'estado',                     // ENUM('activo','inactivo','bloqueado')
    ];

    protected $hidden = [
        'password_hash',
        'respuesta_seguridad_hash',
    ];

    protected $casts = [
        // estado es string ENUM — sin cast a integer
        'ultimo_acceso'       => 'datetime',
        'fecha_creacion'      => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    /**
     * Sanctum usa getAuthPassword(); apuntamos a password_hash.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    // ── Relaciones ──────────────────────────────────────────
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Rol::class,
            'usuario_rol',
            'id_usuario',
            'id_rol'
        )->withPivot(['id_usuario_rol', 'estado', 'fecha_asignacion', 'fecha_desasignacion']);
    }

    /** Roles activos del usuario (ENUM 'activo') */
    public function rolesActivos(): BelongsToMany
    {
        return $this->roles()->wherePivot('estado', 'activo');
    }

    public function docente(): HasOne
    {
        return $this->hasOne(Docente::class, 'id_usuario', 'id_usuario');
    }

    // ── Helpers ──────────────────────────────────────────────
    /**
     * Verifica si el usuario tiene un rol activo por nombre.
     */
    public function tieneRol(string $nombreRol): bool
    {
        return $this->rolesActivos()->where('nombre_rol', $nombreRol)->exists();
    }

    public function nombresCompletos(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }

    public function estaBloqueado(): bool
    {
        return $this->estado === self::ESTADO_BLOQUEADO;
    }

    public function estaActivo(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActivos($query)
    {
        return $query->where('estado', 'activo');
    }

    public function scopeNoBloqueados($query)
    {
        return $query->where('estado', '!=', 'bloqueado');
    }
}
