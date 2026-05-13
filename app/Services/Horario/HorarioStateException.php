<?php

namespace App\Services\Horario;

/**
 * Excepción interna del HorarioStateService.
 * Lanzada dentro de DB::transaction() para provocar rollback con contexto.
 */
final class HorarioStateException extends \RuntimeException
{
    public function __construct(
        public readonly string $tipo,
        public readonly string $mensaje,
    ) {
        parent::__construct($mensaje);
    }
}
