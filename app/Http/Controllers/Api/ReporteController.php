<?php

namespace App\Http\Controllers\Api;

use App\Exports\HorarioCarreraExport;
use App\Exports\HorarioDocenteExport;
use App\Exports\SeccionesNoAsignadasExport;
use App\Exports\ResumenAsignacionesExport;
use App\Http\Controllers\Controller;
use App\Services\Reporte\ReporteDataService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;

/**
 * ReporteController — Sprint 4, Paso 3
 *
 * Genera reportes en PDF o Excel para los 4 tipos definidos.
 * El formato se controla con ?formato=pdf|excel (default: excel).
 *
 * Rutas (todas bajo auth:sanctum):
 *   GET /api/reportes/horario-carrera         → admin, coord
 *   GET /api/reportes/horario-docente         → admin, coord, docente
 *   GET /api/reportes/secciones-no-asignadas  → admin, coord
 *   GET /api/reportes/resumen-asignaciones    → admin, coord
 *
 * Permisos:
 *   - Admin: sin restricción de carrera.
 *   - Coordinador: solo sus carreras (verificado en ReporteDataService).
 *   - Docente: solo su propio horario (id_docente forzado desde usuario autenticado).
 *   - Estudiante: no accede a reportes de gestión.
 *
 * Dependencias requeridas (composer):
 *   composer require barryvdh/laravel-dompdf
 *   composer require maatwebsite/excel
 */
class ReporteController extends Controller
{
    public function __construct(
        private readonly ReporteDataService $reporteService,
    ) {}

    // ── Reporte 1: Horario por carrera/período ──────────────────

    /**
     * GET /api/reportes/horario-carrera
     *
     * Recibe id_horario (no id_carrera+id_periodo) porque un horario identifica
     * una versión específica del plan horario de una carrera y período.
     * La carrera y período se obtienen del propio registro horario.
     *
     * Params: id_horario (required), formato (pdf|excel, default: excel)
     * Acceso: admin (cualquier horario), coordinador (solo sus carreras)
     */
    public function horarioCarrera(Request $request): mixed
    {
        $request->validate([
            'id_horario' => ['required', 'integer', 'exists:horario,id_horario'],
            'formato'    => ['sometimes', 'in:pdf,excel'],
        ]);

        $idCoord = $this->idCoordinadorSiEsCoord($request);
        $data    = $this->reporteService->horarioCarrera(
            idHorario: (int) $request->id_horario,
            idCoord:   $idCoord,
        );

        if (empty($data)) {
            return response()->json(['message' => 'Horario no encontrado.'], 404);
        }

        $horario  = (object) $data['horario'];
        $detalles = $data['detalles'];
        $nombre   = "horario_{$horario->codigo_carrera}_{$horario->anio}-{$horario->numero_periodo}";

        if ($this->esPdf($request)) {
            $pdf = Pdf::loadView('reportes.horario_carrera', compact('horario', 'detalles'))
                ->setPaper('letter', 'landscape');
            return $pdf->download("{$nombre}.pdf");
        }

        return Excel::download(
            new HorarioCarreraExport($detalles, $horario),
            "{$nombre}.xlsx"
        );
    }

    // ── Reporte 2: Horario por docente ──────────────────────────

    /**
     * GET /api/reportes/horario-docente
     *
     * Params:
     *   id_docente          (requerido para admin/coord; ignorado para docente)
     *   id_periodo_academico (opcional — filtra por período)
     *   id_carrera           (opcional — filtra por carrera)
     *   formato              (pdf|excel)
     *
     * Acceso:
     *   - Docente: usa su propio id_docente, ignora el parámetro.
     *   - Coordinador: solo ve clases en sus carreras.
     *   - Admin: sin restricción.
     */
    public function horarioDocente(Request $request): mixed
    {
        $esDocente = $this->esRol($request, 'docente');
        $esCoord   = $this->esRol($request, 'coordinador');

        $rules = [
            'formato'              => ['sometimes', 'in:pdf,excel'],
            'id_periodo_academico' => ['sometimes', 'nullable', 'integer', 'exists:periodo_academico,id_periodo_academico'],
            'id_carrera'           => ['sometimes', 'nullable', 'integer', 'exists:carrera,id_carrera'],
        ];

        if (! $esDocente) {
            $rules['id_docente'] = ['required', 'integer', 'exists:docente,id_docente'];
        }

        $request->validate($rules);

        // Forzar id_docente propio si el rol es docente
        if ($esDocente) {
            $docente = $request->user()->docente()->first();
            if (! $docente) {
                return response()->json(['message' => 'Perfil docente no encontrado.'], 404);
            }
            $idDocente = $docente->id_docente;
        } else {
            $idDocente = (int) $request->id_docente;
        }

        $idCoord   = $esCoord ? $request->user()->id_usuario : null;
        $idPeriodo = $request->integer('id_periodo_academico') ?: null;
        $idCarrera = $request->integer('id_carrera') ?: null;

        $clases = $this->reporteService->horarioDocente(
            idDocente: $idDocente,
            idPeriodo: $idPeriodo,
            idCarrera: $idCarrera,
            idCoord:   $idCoord,
        );

        // Nombre del docente para el encabezado
        $nombreDocente = empty($clases)
            ? 'Docente'
            : ((object) $clases[0])->nombre_docente ?? 'Docente';

        $nombre = "horario_docente_{$idDocente}";

        if ($this->esPdf($request)) {
            $pdf = Pdf::loadView('reportes.horario_docente', compact('clases', 'nombreDocente'))
                ->setPaper('letter', 'landscape');
            return $pdf->download("{$nombre}.pdf");
        }

        return Excel::download(new HorarioDocenteExport($clases), "{$nombre}.xlsx");
    }

    // ── Reporte 3: Secciones no asignadas ──────────────────────

    /**
     * GET /api/reportes/secciones-no-asignadas
     *
     * Params: id_carrera, id_periodo_academico, id_horario, formato
     * Acceso: admin, coordinador (solo sus carreras)
     *
     * Devuelve dos categorías separadas:
     *   SIN_DOCENTE           — sección sin asignación docente activa
     *   SIN_BLOQUE_EN_HORARIO — sección con docente pero sin detalle en el horario
     */
    public function seccionesNoAsignadas(Request $request): mixed
    {
        $request->validate([
            'id_carrera'           => ['required', 'integer', 'exists:carrera,id_carrera'],
            'id_periodo_academico' => ['required', 'integer', 'exists:periodo_academico,id_periodo_academico'],
            'id_horario'           => ['required', 'integer', 'exists:horario,id_horario'],
            'formato'              => ['sometimes', 'in:pdf,excel'],
        ]);

        $idCoord = $this->idCoordinadorSiEsCoord($request);
        $datos   = $this->reporteService->seccionesNoAsignadas(
            idCarrera: (int) $request->id_carrera,
            idPeriodo: (int) $request->id_periodo_academico,
            idHorario: (int) $request->id_horario,
            idCoord:   $idCoord,
        );

        $nombre = "secciones_no_asignadas_carrera{$request->id_carrera}_p{$request->id_periodo_academico}";

        if ($this->esPdf($request)) {
            $pdf = Pdf::loadView('reportes.secciones_no_asignadas', compact('datos'));
            return $pdf->download("{$nombre}.pdf");
        }

        return Excel::download(new SeccionesNoAsignadasExport($datos), "{$nombre}.xlsx");
    }

    // ── Reporte 4: Resumen de asignaciones ──────────────────────

    /**
     * GET /api/reportes/resumen-asignaciones
     *
     * Params: id_carrera, id_periodo_academico, id_horario (opcional), formato
     * Acceso: admin, coordinador (solo sus carreras)
     *
     * total_secciones_asignadas = COUNT(DISTINCT adc.id_asignacion_docente_curso)
     * total_bloques_horario     = COUNT(dh.id_detalle_horario)
     * → Diferencia carga académica de bloques ocupados.
     */
    public function resumenAsignaciones(Request $request): mixed
    {
        $request->validate([
            'id_carrera'           => ['required', 'integer', 'exists:carrera,id_carrera'],
            'id_periodo_academico' => ['required', 'integer', 'exists:periodo_academico,id_periodo_academico'],
            'id_horario'           => ['sometimes', 'nullable', 'integer', 'exists:horario,id_horario'],
            'formato'              => ['sometimes', 'in:pdf,excel'],
        ]);

        $idCoord   = $this->idCoordinadorSiEsCoord($request);
        $idHorario = $request->integer('id_horario') ?: null;

        $filas = $this->reporteService->resumenAsignaciones(
            idCarrera: (int) $request->id_carrera,
            idPeriodo: (int) $request->id_periodo_academico,
            idHorario: $idHorario,
            idCoord:   $idCoord,
        );

        $nombre = "resumen_asignaciones_carrera{$request->id_carrera}_p{$request->id_periodo_academico}";

        if ($this->esPdf($request)) {
            $pdf = Pdf::loadView('reportes.resumen_asignaciones',
                compact('filas', 'idHorario'));
            return $pdf->download("{$nombre}.pdf");
        }

        return Excel::download(new ResumenAsignacionesExport($filas), "{$nombre}.xlsx");
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function esPdf(Request $request): bool
    {
        return strtolower($request->input('formato', 'excel')) === 'pdf';
    }

    private function esRol(Request $request, string $rol): bool
    {
        return $request->user()->ultimo_perfil_activo === $rol;
    }

    /**
     * Si el perfil activo es coordinador, retorna su id_usuario.
     * Admin → null (sin restricción de carrera en el servicio).
     */
    private function idCoordinadorSiEsCoord(Request $request): ?int
    {
        return $this->esRol($request, 'coordinador')
            ? $request->user()->id_usuario
            : null;
    }
}
