<?php

namespace App\Services\Horario;

/**
 * Excepción interna lanzada dentro de la transacción de edición manual.
 *
 * Al ser lanzada dentro de DB::transaction() provoca rollback automático.
 * El handler la captura para construir el EdicionManualResultado de rechazo
 * con el tipo y mensaje exacto.
 *
 * Interna al servicio — no debe propagarse fuera de EdicionManualService.
 */
final class EdicionManualException extends \RuntimeException
{
    public function __construct(
        public readonly string               $tipo,
        public readonly string               $mensaje,
        public readonly ?ValidacionResultado $validacion = null,
    ) {
        parent::__construct($mensaje);
    }
}
