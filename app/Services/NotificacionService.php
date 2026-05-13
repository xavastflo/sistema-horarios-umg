<?php

namespace App\Services;

use App\Models\Notificacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NotificacionService — Sprint 4, Paso 2
 *
 * Servicio inyectable de Laravel. Centraliza la creación de notificaciones.
 *
 * ── Flujo de integración ─────────────────────────────────────────
 *
 * Las notificaciones se crean DESPUÉS del commit del evento principal:
 *
 *   [transacción principal] → commit
 *   → construir $resultado
 *   → ejecutar notificación en try/catch   ← aquí
 *   → return $resultado
 *
 * Si la notificación falla, se registra con Log::error() sin relanzar
 * la excepción. El caller recibe su resultado sin saber si la
 * notificación falló.
 *
 * ── Reglas de destinatarios ──────────────────────────────────────
 *
 * - Se eliminan valores null antes de deduplicar.
 * - Se deduplica con array_unique para que ningún usuario reciba
 *   dos notificaciones del mismo evento (ej. coordinador que también
 *   tiene clases en ese horario).
 * - No se notifica a estudiantes — no existe estudiante_carrera en
 *   el modelo oficial.
 * - Si id_usuario_coordinador es null (carrera sin coordinador),
 *   se ignora silenciosamente.
 */
class NotificacionService
{
    // ── Método central ──────────────────────────────────────────

    /**
     * Crea una notificación para cada usuario en la lista.
     * Filtra nulls, deduplica y maneja errores individuales.
     *
     * @param array  $idsUsuarios  IDs de destinatarios (puede tener nulls y duplicados)
     * @param string $titulo       varchar(100) — se trunca si excede
     * @param string $mensaje      varchar(255) — se trunca si excede
     * @param string $tipo         ENUM oficial: cambio_horario|bloqueo_horario|aprobacion_horario|general
     */
    public function enviar(
        array  $idsUsuarios,
        string $titulo,
        string $mensaje,
        string $tipo = Notificacion::TIPO_GENERAL,
    ): void {
        // Filtrar nulls y deduplicar
        $destinatarios = array_values(
            array_unique(
                array_filter($idsUsuarios, fn($id) => $id !== null && $id > 0)
            )
        );

        if (empty($destinatarios)) {
            return;
        }

        // Truncar a los límites del SQL oficial para evitar errores de columna
        $titulo  = mb_substr($titulo,  0, 100);
        $mensaje = mb_substr($mensaje, 0, 255);

        $ahora = now();

        foreach ($destinatarios as $idUsuario) {
            try {
                Notificacion::create([
                    'id_usuario'        => $idUsuario,
                    'titulo'            => $titulo,
                    'mensaje'           => $mensaje,
                    'tipo_notificacion' => $tipo,
                    'leida'             => false,
                    'fecha_envio'       => $ahora,
                    'fecha_lectura'     => null,
                    'estado'            => 'activo',
                ]);
            } catch (\Throwable $e) {
                Log::error('NotificacionService::enviar — fallo al insertar', [
                    'id_usuario' => $idUsuario,
                    'titulo'     => $titulo,
                    'tipo'       => $tipo,
                    'error'      => $e->getMessage(),
                ]);
                // No se relanza — se continúa con el resto de destinatarios
            }
        }
    }

    // ── Métodos de evento ───────────────────────────────────────

    /**
     * Horario aprobado.
     * Destinatarios: coordinador de la carrera + docentes con clase activa.
     * Tipo: aprobacion_horario
     */
    public function horarioAprobado(
        int    $idHorario,
        int    $idCarrera,
        string $nombreCarrera,
        string $nombrePeriodo,
    ): void {
        $destinatarios = $this->resolverCoordinadorYDocentes($idHorario, $idCarrera);

        $this->enviar(
            idsUsuarios: $destinatarios,
            titulo:      'Horario aprobado',
            mensaje:     "El horario de {$nombreCarrera} — {$nombrePeriodo} ha sido aprobado.",
            tipo:        Notificacion::TIPO_APROBACION,
        );
    }

    /**
     * Horario bloqueado.
     * Destinatarios: coordinador + docentes con clase activa.
     * Tipo: bloqueo_horario
     */
    public function horarioBloqueado(
        int    $idHorario,
        int    $idCarrera,
        string $nombreCarrera,
        string $nombrePeriodo,
    ): void {
        $destinatarios = $this->resolverCoordinadorYDocentes($idHorario, $idCarrera);

        $this->enviar(
            idsUsuarios: $destinatarios,
            titulo:      'Horario bloqueado',
            mensaje:     "El horario de {$nombreCarrera} — {$nombrePeriodo} ha sido bloqueado.",
            tipo:        Notificacion::TIPO_BLOQUEO_HORARIO,
        );
    }

    /**
     * Horario publicado.
     * Destinatarios: coordinador + docentes con clase activa.
     * Sin notificación a estudiantes — no existe estudiante_carrera.
     * Tipo: general (no existe 'publicacion_horario' en el ENUM oficial)
     */
    public function horarioPublicado(
        int    $idHorario,
        int    $idCarrera,
        string $nombreCarrera,
        string $nombrePeriodo,
    ): void {
        $destinatarios = $this->resolverCoordinadorYDocentes($idHorario, $idCarrera);

        $this->enviar(
            idsUsuarios: $destinatarios,
            titulo:      'Horario publicado',
            mensaje:     "El horario de {$nombreCarrera} — {$nombrePeriodo} ya está publicado.",
            tipo:        Notificacion::TIPO_GENERAL,
        );
    }

    /**
     * Detalle de horario movido manualmente.
     * Destinatarios: docente afectado + coordinador de la carrera.
     * Tipo: cambio_horario
     *
     * @param int    $idUsuarioDocente  Usuario del docente afectado
     * @param int    $idCarrera         Para resolver el coordinador
     * @param string $nombreCurso
     * @param string $bloqueAnterior    Descripción legible "lunes 18:00-19:30"
     * @param string $bloqueNuevo       Descripción legible "martes 18:00-19:30"
     */
    public function detalleMovido(
        int    $idUsuarioDocente,
        int    $idCarrera,
        string $nombreCurso,
        string $bloqueAnterior,
        string $bloqueNuevo,
    ): void {
        $idCoord       = $this->resolverCoordinador($idCarrera);
        $destinatarios = [$idUsuarioDocente, $idCoord];

        $this->enviar(
            idsUsuarios: $destinatarios,
            titulo:      'Clase reubicada en el horario',
            mensaje:     "{$nombreCurso} fue movida de {$bloqueAnterior} a {$bloqueNuevo}.",
            tipo:        Notificacion::TIPO_CAMBIO_HORARIO,
        );
    }

    /**
     * Detalle de horario eliminado manualmente.
     * Destinatarios: docente afectado + coordinador de la carrera.
     * Tipo: cambio_horario
     *
     * @param int    $idUsuarioDocente  Usuario del docente afectado
     * @param int    $idCarrera         Para resolver el coordinador
     * @param string $nombreCurso
     * @param string $bloqueDescripcion Descripción legible del bloque eliminado
     */
    public function detalleEliminado(
        int    $idUsuarioDocente,
        int    $idCarrera,
        string $nombreCurso,
        string $bloqueDescripcion,
    ): void {
        $idCoord       = $this->resolverCoordinador($idCarrera);
        $destinatarios = [$idUsuarioDocente, $idCoord];

        $this->enviar(
            idsUsuarios: $destinatarios,
            titulo:      'Clase eliminada del horario',
            mensaje:     "{$nombreCurso} fue eliminada del bloque {$bloqueDescripcion}.",
            tipo:        Notificacion::TIPO_CAMBIO_HORARIO,
        );
    }

    /**
     * Horario confirmado (persistencia exitosa tras generación automática).
     * Destinatario: solo el coordinador.
     * Los docentes recibirán notificación cuando el horario sea aprobado/publicado.
     * Tipo: general
     */
    public function horarioConfirmado(
        int    $idCarrera,
        string $nombreCarrera,
        string $nombrePeriodo,
        int    $totalDetalles,
    ): void {
        $idCoord = $this->resolverCoordinador($idCarrera);

        $this->enviar(
            idsUsuarios: [$idCoord],
            titulo:      'Horario generado y confirmado',
            mensaje:     "El horario de {$nombreCarrera} — {$nombrePeriodo} fue generado con {$totalDetalles} clases asignadas.",
            tipo:        Notificacion::TIPO_GENERAL,
        );
    }

    /**
     * Horario limpiado para regeneración (limpiarDetalles exitoso).
     * Destinatario: solo el coordinador.
     * Tipo: general
     */
    public function horarioRegenerado(
        int    $idCarrera,
        string $nombreCarrera,
        string $nombrePeriodo,
    ): void {
        $idCoord = $this->resolverCoordinador($idCarrera);

        $this->enviar(
            idsUsuarios: [$idCoord],
            titulo:      'Horario reiniciado para regeneración',
            mensaje:     "El horario de {$nombreCarrera} — {$nombrePeriodo} fue limpiado y vuelto a estado borrador.",
            tipo:        Notificacion::TIPO_GENERAL,
        );
    }

    // ── Resolución de destinatarios ─────────────────────────────

    /**
     * Devuelve el id_usuario_coordinador de la carrera.
     * Retorna null si la carrera no tiene coordinador asignado.
     * El null se filtra en enviar().
     */
    private function resolverCoordinador(int $idCarrera): ?int
    {
        return DB::table('carrera')
            ->where('id_carrera', $idCarrera)
            ->value('id_usuario_coordinador');
    }

    /**
     * Devuelve [id_usuario_coordinador, ...ids_usuarios_docentes_con_clase_activa].
     * Los nulls y duplicados se filtran en enviar().
     *
     * Los docentes se resuelven desde detalle_horario activo → asignacion →
     * docente → usuario. Se usa DISTINCT para que un docente con varias
     * clases en el mismo horario aparezca solo una vez.
     */
    private function resolverCoordinadorYDocentes(int $idHorario, int $idCarrera): array
    {
        $idCoord = $this->resolverCoordinador($idCarrera);

        $idsDocentes = DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc',
                'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->join('docente as d', 'adc.id_docente', '=', 'd.id_docente')
            ->where('dh.id_horario', $idHorario)
            ->where('dh.estado', 'activo')
            ->where('adc.estado', 'activo')
            ->distinct()
            ->pluck('d.id_usuario')
            ->toArray();

        return array_merge([$idCoord], $idsDocentes);
        // enviar() deduplica en caso de que el coordinador también sea docente
    }
}
