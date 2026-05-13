<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistorialCambios extends Model
{
    protected $table      = 'historial_cambios';
    protected $primaryKey = 'id_historial_cambios';
    public    $timestamps = false;

    const TIPOS = [
        'insert',
        'update',
        'delete',
        'aprobacion',
        'bloqueo',
        'duplicacion',
        'asignacion',
    ];

    protected $fillable = [
        'id_usuario',             // NOT NULL en SQL oficial
        'tabla_afectada',
        'id_registro_afectado',
        'tipo_cambio',
        'valor_anterior',         // text — se guardará como JSON string
        'valor_nuevo',
        'motivo_cambio',
        'fecha_cambio',
    ];

    protected $casts = [
        // SQL oficial: text — guardamos JSON serializado manualmente
        // NO usar cast 'array' porque la columna es TEXT no JSON nativo
        'fecha_cambio'         => 'datetime',
        'id_registro_afectado' => 'integer',
        'id_usuario'           => 'integer',
    ];

    // ── Serialización de valores ────────────────────────────
    /**
     * Convierte array a JSON string antes de guardar en columna TEXT.
     */
    public function setValorAnteriorAttribute(?array $value): void
    {
        $this->attributes['valor_anterior'] = $value ? json_encode($value, JSON_UNESCAPED_UNICODE) : null;
    }

    public function setValorNuevoAttribute(?array $value): void
    {
        $this->attributes['valor_nuevo'] = $value ? json_encode($value, JSON_UNESCAPED_UNICODE) : null;
    }

    public function getValorAnteriorAttribute(?string $value): ?array
    {
        return $value ? json_decode($value, true) : null;
    }

    public function getValorNuevoAttribute(?string $value): ?array
    {
        return $value ? json_decode($value, true) : null;
    }

    // ── Relaciones ──────────────────────────────────────────
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopePorTabla($query, string $tabla)
    {
        return $query->where('tabla_afectada', $tabla);
    }

    public function scopePorRegistro($query, string $tabla, int $id)
    {
        return $query->where('tabla_afectada', $tabla)
                     ->where('id_registro_afectado', $id);
    }
}
