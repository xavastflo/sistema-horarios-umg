<?php

namespace App\Services\Horario;

/**
 * Excepción interna lanzada dentro de la transacción de persistencia
 * cuando una propuesta falla la revalidación.
 *
 * Al ser lanzada dentro de DB::transaction(), provoca el rollback
 * automático. El manejador la captura para construir el PersistenciaResultado
 * de fallo con el contexto exacto del conflicto.
 *
 * Es interna al servicio — no debe propagarse fuera de PersistenciaHorarioService.
 */
final class PersistenciaConflictoException extends \RuntimeException
{
    public function __construct(
        public readonly AsignacionPropuesta $propuesta,
        public readonly ValidacionResultado $conflicto,
    ) {
        parent::__construct(
            "Conflicto al persistir propuesta para sección {$propuesta->idSeccion}: "
            . ($conflicto->primerConflicto()?->mensaje ?? 'sin detalle')
        );
    }
}
