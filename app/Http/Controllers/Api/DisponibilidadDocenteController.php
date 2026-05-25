<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DisponibilidadDocente\StoreDisponibilidadRequest;
use App\Models\BloqueHorario;
use App\Models\Docente;
use App\Models\DisponibilidadDocente;
use App\Services\HistorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DisponibilidadDocenteController
 *
 * REGLA DE NEGOCIO:
 *   La disponibilidad es un atributo del DOCENTE, no de la carrera.
 *   Si un docente no puede el Lunes 18:00, no puede para NINGUNA carrera.
 *
 *   Implementación: los BloqueHorario comparten (hora_inicio, hora_fin, id_dia)
 *   pero tienen distintos id_carrera_jornada (son "bloques hermanos").
 *   Al marcar una franja, se registra la restricción para TODOS los hermanos.
 *
 * REGLA DE ACCESO (seguridad de fila):
 *   - Admin / Coordinador → pueden gestionar cualquier docente.
 *   - Docente             → solo puede gestionar SU PROPIO id_docente.
 */
class DisponibilidadDocenteController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // HELPER: seguridad de fila
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Devuelve null si el acceso es válido, o JsonResponse 403 si no.
     */
    private function verificarAcceso(Request $request, int $idDocente): ?JsonResponse
    {
        $perfil = $request->user()->ultimo_perfil_activo;

        if (in_array($perfil, ['administrador', 'coordinador'])) {
            return null;
        }

        $propio = $request->user()->docente()->first();

        if (! $propio || $propio->id_docente !== $idDocente) {
            return response()->json([
                'message' => 'Solo puedes gestionar tu propia disponibilidad.',
            ], 403);
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/docentes/{docente}/disponibilidad
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request, int $idDocente): JsonResponse
    {
        if ($error = $this->verificarAcceso($request, $idDocente)) {
            return $error;
        }

        $docente = Docente::findOrFail($idDocente);

        $disponibilidades = DisponibilidadDocente::with([
            'bloqueHorario.dia',
            'bloqueHorario.carreraJornada.jornada',
        ])
        ->where('id_docente', $idDocente)
        ->where('estado', 'activo')
        ->orderBy('id_bloque_horario')
        ->get();

        return response()->json([
            'docente' => [
                'id_docente'     => $docente->id_docente,
                'codigo_docente' => $docente->codigo_docente,
            ],
            'bloques_no_disponibles' => $disponibilidades,
            'total'                  => $disponibilidades->count(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/docentes/{docente}/disponibilidad
    // ─────────────────────────────────────────────────────────────────────────

    public function store(StoreDisponibilidadRequest $request, int $idDocente): JsonResponse
    {
        if ($error = $this->verificarAcceso($request, $idDocente)) {
            return $error;
        }

        $docente = Docente::where('id_docente', $idDocente)
            ->where('estado', 'activo')
            ->firstOrFail();

        BloqueHorario::where('id_bloque_horario', $request->id_bloque_horario)
            ->where('estado', 'activo')
            ->firstOrFail();

        $existente = DisponibilidadDocente::where('id_docente', $idDocente)
            ->where('id_bloque_horario', $request->id_bloque_horario)
            ->first();

        if ($existente) {
            if ($existente->estado === 'activo') {
                return response()->json([
                    'message' => 'El docente ya tiene ese bloque marcado como no disponible.',
                ], 422);
            }
            $existente->update([
                'estado'              => 'activo',
                'observacion'         => $request->observacion,
                'fecha_actualizacion' => now(),
            ]);
            $disponibilidad = $existente;
        } else {
            $disponibilidad = DisponibilidadDocente::create([
                'id_docente'          => $idDocente,
                'id_bloque_horario'   => $request->id_bloque_horario,
                'observacion'         => $request->observacion,
                'estado'              => 'activo',
                'fecha_registro'      => now(),
                'fecha_actualizacion' => now(),
            ]);
        }

        HistorialService::registrar(
            tabla:      'disponibilidad_docente',
            idRegistro: $disponibilidad->id_disponibilidad_docente,
            tipoCambio: 'insert',
            valorNuevo: [
                'id_docente'        => $idDocente,
                'id_bloque_horario' => $request->id_bloque_horario,
            ],
            motivo: 'Bloque marcado como no disponible',
        );

        return response()->json([
            'message'        => 'Bloque marcado como no disponible.',
            'disponibilidad' => $disponibilidad->load('bloqueHorario.dia'),
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/docentes/{docente}/disponibilidad/{disponibilidad}
    // ─────────────────────────────────────────────────────────────────────────

    public function destroy(Request $request, int $idDocente, int $idDisponibilidad): JsonResponse
    {
        if ($error = $this->verificarAcceso($request, $idDocente)) {
            return $error;
        }

        $disponibilidad = DisponibilidadDocente::where('id_docente', $idDocente)
            ->where('id_disponibilidad_docente', $idDisponibilidad)
            ->where('estado', 'activo')
            ->firstOrFail();

        HistorialService::registrar(
            tabla:         'disponibilidad_docente',
            idRegistro:    $disponibilidad->id_disponibilidad_docente,
            tipoCambio:    'delete',
            valorAnterior: $disponibilidad->toArray(),
            motivo:        'Bloque desmarcado — docente vuelve a estar disponible',
        );

        $disponibilidad->update(['estado' => 'inactivo']);

        return response()->json([
            'message' => 'Bloque desmarcado. El docente ahora está disponible en ese horario.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/docentes/{docente}/disponibilidad/toggle
    //
    // Recibe una FRANJA (hora_inicio, hora_fin, nombre_dia) y opera sobre
    // TODOS los bloques hermanos (mismo horario, distinto id_carrera_jornada).
    //
    // Lógica de negocio:
    //   Si ALGÚN bloque de la franja no está restringido → restringir todos.
    //   Si TODOS los bloques ya están restringidos       → liberar todos.
    // ─────────────────────────────────────────────────────────────────────────

    public function toggle(Request $request, int $idDocente): JsonResponse
    {
        if ($error = $this->verificarAcceso($request, $idDocente)) {
            return $error;
        }

        $request->validate([
            'hora_inicio' => ['required', 'string', 'regex:/^\d{2}:\d{2}/'],
            'hora_fin'    => ['required', 'string', 'regex:/^\d{2}:\d{2}/'],
            'nombre_dia'  => ['required', 'string'],
            'accion'      => ['required', 'in:restringir,liberar'],
            'observacion' => ['nullable', 'string', 'max:200'],
        ]);

        Docente::where('estado', 'activo')->findOrFail($idDocente);

        // ── 1. Normalizar nombre_dia: el frontend envía 'Sábado','Miércoles' (con tildes)
        //       pero el seeder guardó 'sabado','miercoles' (minúsculas sin tilde).
        $nombreDiaNormalizado = strtolower(trim($request->nombre_dia));
        $nombreDiaNormalizado = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü'],
            ['a', 'e', 'i', 'o', 'u', 'u'],
            $nombreDiaNormalizado
        );

        // ── 2. Buscar todos los bloques hermanos (misma franja, mismo día) ──
        $bloquesHermanos = BloqueHorario::whereHas('dia', fn($q) =>
                $q->where('nombre_dia', $nombreDiaNormalizado)
            )
            ->where('hora_inicio', 'like', $request->hora_inicio . '%')
            ->where('hora_fin',    'like', $request->hora_fin    . '%')
            ->where('estado', 'activo')
            ->pluck('id_bloque_horario');

        if ($bloquesHermanos->isEmpty()) {
            return response()->json([
                'message' => 'No se encontraron bloques activos para esa franja horaria.',
            ], 422);
        }

        // ── 2. Acción explícita enviada por el frontend ───────────────────────
        // El frontend conoce el estado visual de la celda y manda 'accion'
        // directamente. Sin adivinanza ni estados parciales ambiguos.
        $accion = $request->accion; // 'restringir' | 'liberar'

        // ── 3. Ejecutar en transacción ────────────────────────────────────────
        DB::transaction(function () use (
            $bloquesHermanos, $idDocente, $accion, $request
        ) {
            foreach ($bloquesHermanos as $idBloque) {
                $existente = DisponibilidadDocente::where('id_docente', $idDocente)
                    ->where('id_bloque_horario', $idBloque)
                    ->first();

                if ($accion === 'restringir') {
                    if ($existente) {
                        if ($existente->estado !== 'activo') {
                            $existente->update([
                                'estado'              => 'activo',
                                'observacion'         => $request->observacion,
                                'fecha_actualizacion' => now(),
                            ]);
                        }
                        // ya activo → no duplicar
                    } else {
                        $nuevo = DisponibilidadDocente::create([
                            'id_docente'          => $idDocente,
                            'id_bloque_horario'   => $idBloque,
                            'observacion'         => $request->observacion,
                            'estado'              => 'activo',
                            'fecha_registro'      => now(),
                            'fecha_actualizacion' => now(),
                        ]);
                        HistorialService::registrar(
                            tabla:      'disponibilidad_docente',
                            idRegistro: $nuevo->id_disponibilidad_docente,
                            tipoCambio: 'insert',
                            valorNuevo: [
                                'id_docente'        => $idDocente,
                                'id_bloque_horario' => $idBloque,
                                'franja'            => "{$request->nombre_dia} {$request->hora_inicio}-{$request->hora_fin}",
                            ],
                            motivo: 'Toggle: franja marcada como no disponible (todos los hermanos)',
                        );
                    }
                } else {
                    // liberar
                    if ($existente && $existente->estado === 'activo') {
                        HistorialService::registrar(
                            tabla:         'disponibilidad_docente',
                            idRegistro:    $existente->id_disponibilidad_docente,
                            tipoCambio:    'delete',
                            valorAnterior: $existente->toArray(),
                            motivo:        'Toggle: franja liberada (todos los hermanos)',
                        );
                        $existente->update(['estado' => 'inactivo']);
                    }
                }
            }
        });

        return response()->json([
            'message'       => $accion === 'restringir'
                ? "Franja marcada como no disponible ({$bloquesHermanos->count()} bloque(s) hermanos)."
                : "Franja liberada ({$bloquesHermanos->count()} bloque(s) hermanos).",
            'disponible'    => $accion === 'liberar',
            'bloques_afectados' => $bloquesHermanos->count(),
        ]);
    }
    // ─────────────────────────────────────────────────────────────────────────
    // HELPER: normalizar nombre_dia
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convierte el nombre_dia del seeder (minúsculas sin tilde) al formato
     * que espera el frontend en ORDEN_DIAS (Title Case con tilde).
     *
     * Seeder:   'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'
     * Frontend: 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'
     */
    private function normalizarNombreDia(?string $nombre): ?string
    {
        if (! $nombre) return null;

        $mapa = [
            'lunes'     => 'Lunes',
            'martes'    => 'Martes',
            'miercoles' => 'Miércoles',
            'jueves'    => 'Jueves',
            'viernes'   => 'Viernes',
            'sabado'    => 'Sábado',
            'domingo'   => 'Domingo',
        ];

        // Normalizar la clave: quitar tildes y pasar a minúsculas para lookup seguro
        $clave = strtolower($nombre);
        $clave = str_replace(['á','é','í','ó','ú','ü'], ['a','e','i','o','u','u'], $clave);

        return $mapa[$clave] ?? ucfirst($nombre);
    }

    //
    // Retorna SOLO los bloques horarios de las jornadas en las que el docente
    // tiene asignaciones activas. Días derivados de esos bloques (dinámicos).
    //
    // Cadena de relaciones:
    //   asignacion_docente_curso (id_docente)
    //     → seccion (id_curso)
    //       → pensum_curso (id_curso) → pensum (id_carrera)
    //         → carrera_jornada (id_carrera)
    //           → bloque_horario (id_carrera_jornada)
    //             → dia (id_dia)
    //
    // Si el docente no tiene asignaciones, retorna los bloques globales
    // como fallback para que la cuadrícula no quede vacía.
    // ─────────────────────────────────────────────────────────────────────────
    public function bloquesPorDocente(Request $request, int $idDocente): JsonResponse
    {
        if ($error = $this->verificarAcceso($request, $idDocente)) {
            return $error;
        }

        // 1. Obtener id_carrera_jornada de las asignaciones activas del docente
        //    Si viene id_jornada, filtrar solo esa jornada específica
        $queryBase = DB::table('asignacion_docente_curso as adc')
            ->join('seccion as s', 'adc.id_seccion', '=', 's.id_seccion')
            ->join('pensum_curso as pc', 'pc.id_curso', '=', 's.id_curso')
            ->join('pensum as p', 'p.id_pensum', '=', 'pc.id_pensum')
            ->join('carrera_jornada as cj', 'cj.id_carrera', '=', 'p.id_carrera')
            ->where('adc.id_docente', $idDocente)
            ->where('adc.estado', 'activo')
            ->where('s.estado', 'activo')
            ->where('pc.estado', 'activo')
            ->where('p.estado', 'activo')
            ->where('cj.estado', 'activo');

        // Filtro opcional de jornada — permite segmentar la cuadrícula por jornada
        if ($request->filled('id_jornada')) {
            $queryBase->where('cj.id_jornada', (int) $request->id_jornada);
        }

        $carreraJornadaIds = $queryBase
            ->pluck('cj.id_carrera_jornada')
            ->unique()
            ->values();

        // 2. Obtener bloques de esas jornadas (o todos si no hay asignaciones)
        $query = BloqueHorario::with('dia')
            ->where('estado', 'activo')
            ->orderBy('id_dia')
            ->orderBy('hora_inicio');

        if ($carreraJornadaIds->isNotEmpty()) {
            $query->whereIn('id_carrera_jornada', $carreraJornadaIds);

        } elseif ($request->filled('id_jornada')) {
            // Jornada específica pero sin asignaciones — no mostrar nada
            $query->whereIn('id_carrera_jornada', []);
        }

        $bloques = $query->get();

        // 3. Deduplicar por franja (hora_inicio + hora_fin + id_dia)
        //    Un docente puede estar en varias carreras con mismos horarios
        $franjasSeen = [];
        $bloquesFiltrados = $bloques->filter(function ($b) use (&$franjasSeen) {
            $key = "{$b->id_dia}|{$b->hora_inicio}|{$b->hora_fin}";
            if (in_array($key, $franjasSeen)) return false;
            $franjasSeen[] = $key;
            return true;
        })->values();

        return response()->json([
            'id_docente'       => $idDocente,
            'id_jornada'       => $request->filled('id_jornada') ? (int) $request->id_jornada : null,
            'con_asignaciones' => $carreraJornadaIds->isNotEmpty(),
            'total_jornadas'   => $carreraJornadaIds->count(),
            'bloques'          => $bloquesFiltrados->map(fn($b) => [
                'id_bloque_horario' => $b->id_bloque_horario,
                'hora_inicio'       => $b->hora_inicio,
                'hora_fin'          => $b->hora_fin,
                'nombre_dia'        => $this->normalizarNombreDia($b->dia?->nombre_dia),
            ]),
        ]);
    }

}
