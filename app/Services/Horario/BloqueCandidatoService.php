<?php

namespace App\Services\Horario;

use App\Models\BloqueHorario;
use App\Models\Horario;
use App\Models\PeriodoAcademico;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BloqueCandidatoService
 *
 * Responsabilidad: dado un docente, una sección y un horario,
 * devuelve el conjunto de bloques válidos donde se puede asignar
 * esa sección, junto con los bloques descartados y su motivo.
 *
 * ── Estrategia de eficiencia ────────────────────────────────────
 *
 * Precarga en 4 queries todos los datos de exclusión. Los filtros
 * 2, 3 y 4 son lookups en memoria contra esos sets. Solo el Filtro 5
 * (traslape de ciclo) ejecuta 1 query adicional por bloque candidato
 * que supere los filtros previos.
 *
 * ── Corrección crítica (Paso 2 — revisión) ─────────────────────
 *
 * Los filtros 2 (disponibilidad) y 3 (docente ocupado globalmente)
 * ya NO comparan por id_bloque_horario. Comparan por:
 *   id_dia + traslape(hora_inicio, hora_fin)
 *
 * Esto detecta conflictos entre carreras con bloques distintos
 * pero en el mismo día y franja horaria:
 *   Carrera A: bloque ID 10, martes 18:00-19:30
 *   Carrera B: bloque ID 25, martes 18:00-19:30
 *   → Ambos son incompatibles aunque tengan IDs distintos ✓
 *
 * Condición de traslape:
 *   bloque_existente.id_dia   = bloque_candidato.id_dia
 *   bloque_existente.hora_inicio < bloque_candidato.hora_fin
 *   bloque_existente.hora_fin    > bloque_candidato.hora_inicio
 *
 * ── Estado 'bloqueado' incluido ─────────────────────────────────
 *
 * Un horario bloqueado es un compromiso real del docente.
 * Se incluye en todos los filtros de ocupación global.
 *
 * ── Diagrama de filtros ─────────────────────────────────────────
 *
 *   Todos los bloques activos de la carrera-jornada (Query 1)
 *        │
 *        ▼ Filtro 2: traslape con bloqueos de disponibilidad (set B)
 *        │
 *        ▼ Filtro 3: traslape con clases globales del docente (set C)
 *        │
 *        ▼ Filtro 4: bloque exacto ocupado en este horario  (set D)
 *        │
 *        ▼ Filtro 5: ciclo sin traslape (1 query por bloque restante)
 *        │
 *        └──→ BloqueCandidatoResultado { válidos, descartados }
 */
class BloqueCandidatoService
{
    public function __construct(
        private readonly ConflictValidationService $conflictService,
    ) {}

    // ── Punto de entrada principal ──────────────────────────────

    /**
     * Obtiene los bloques candidatos válidos para asignar una sección
     * a un docente dentro de un horario específico.
     *
     * @param int              $idDocente         Docente que se quiere asignar
     * @param int              $idSeccion         Sección que se va a programar
     * @param int              $idHorario         Horario en construcción
     * @param int              $idCarreraJornada  Carrera-jornada de la que tomar bloques
     * @param PeriodoAcademico $periodo           Modelo hidratado (para validar fecha límite)
     * @param Horario          $horario           Modelo hidratado (para validar estado)
     */
    public function obtenerCandidatos(
        int              $idDocente,
        int              $idSeccion,
        int              $idHorario,
        int              $idCarreraJornada,
        PeriodoAcademico $periodo,
        Horario          $horario,
    ): BloqueCandidatoResultado {

        $idPeriodo = $periodo->id_periodo_academico;

        // ── Validaciones de contexto (sin queries) ─────────────
        $r1 = $this->conflictService->validarFechaLimite($periodo);
        if ($r1->tieneConflictos()) {
            return BloqueCandidatoResultado::crear(
                bloquesValidos:     collect(),
                bloquesDescartados: collect(),
                contexto: [
                    'motivo_global'      => 'fecha_limite_vencida',
                    'id_docente'         => $idDocente,
                    'id_seccion'         => $idSeccion,
                    'id_horario'         => $idHorario,
                    'id_carrera_jornada' => $idCarreraJornada,
                    'conflicto'          => $r1->primerConflicto()?->toArray(),
                ]
            );
        }

        $r2 = $this->conflictService->validarEstadoHorario($horario);
        if ($r2->tieneConflictos()) {
            return BloqueCandidatoResultado::crear(
                bloquesValidos:     collect(),
                bloquesDescartados: collect(),
                contexto: [
                    'motivo_global'      => 'horario_no_editable',
                    'id_docente'         => $idDocente,
                    'id_seccion'         => $idSeccion,
                    'id_horario'         => $idHorario,
                    'id_carrera_jornada' => $idCarreraJornada,
                    'conflicto'          => $r2->primerConflicto()?->toArray(),
                ]
            );
        }

        // ── Precarga en 4 queries ───────────────────────────────

        // Query 1: bloques activos de la carrera-jornada con JOIN dia
        $todosBloques = $this->cargarBloquesCarreraJornada($idCarreraJornada);

        if ($todosBloques->isEmpty()) {
            return BloqueCandidatoResultado::crear(
                bloquesValidos:     collect(),
                bloquesDescartados: collect(),
                contexto: [
                    'motivo_global'      => 'sin_bloques_definidos',
                    'id_carrera_jornada' => $idCarreraJornada,
                ]
            );
        }

        // Query 2: bloques bloqueados por disponibilidad del docente
        // → Collection de {id_dia, hora_inicio, hora_fin}
        $franjasBloqueadasPorDisponibilidad = $this->cargarFranjasBloqueadasDocente($idDocente);

        // Query 3: bloques donde el docente ya tiene clase (global, cualquier carrera)
        // → Collection de {id_dia, hora_inicio, hora_fin, id_horario, nombre_carrera}
        $franjasOcupadasDocente = $this->cargarFranjasOcupadasDocente($idDocente, $idHorario);

        // Query 4: bloques ya asignados dentro de este horario específico
        // → Mapa id_bloque_horario → {seccion_descripcion}
        $bloquesOcupadosEnHorario = $this->cargarBloquesOcupadosEnHorario($idHorario);

        // ── Evaluación por bloque ───────────────────────────────

        $validos     = collect();
        $descartados = collect();

        foreach ($todosBloques as $bloque) {
            $idBloque = $bloque->id_bloque_horario;

            // Filtro 2 — traslape con disponibilidad del docente
            $conflictoDisponibilidad = $this->buscarTraslape(
                $franjasBloqueadasPorDisponibilidad,
                $bloque->id_dia,
                $bloque->hora_inicio,
                $bloque->hora_fin,
            );

            if ($conflictoDisponibilidad !== null) {
                $descartados->push(new BloqueDescartado(
                    bloque: $bloque,
                    razon:  ValidacionResultado::conConflictos([
                        ConflictoItem::docenteNoDisponible(
                            idDocente:  $idDocente,
                            idBloque:   $idBloque,
                            horaInicio: $bloque->hora_inicio,
                            horaFin:    $bloque->hora_fin,
                            nombreDia:  $bloque->nombre_dia,
                        ),
                    ]),
                ));
                continue;
            }

            // Filtro 3 — traslape con clases globales del docente
            $conflictoOcupacion = $this->buscarTraslape(
                $franjasOcupadasDocente,
                $bloque->id_dia,
                $bloque->hora_inicio,
                $bloque->hora_fin,
            );

            if ($conflictoOcupacion !== null) {
                $descartados->push(new BloqueDescartado(
                    bloque: $bloque,
                    razon:  ValidacionResultado::conConflictos([
                        ConflictoItem::docenteOcupado(
                            idDocente:              $idDocente,
                            idBloque:               $idBloque,
                            horaInicio:             $bloque->hora_inicio,
                            horaFin:                $bloque->hora_fin,
                            nombreDia:              $bloque->nombre_dia,
                            idHorarioConflicto:     $conflictoOcupacion['id_horario'],
                            nombreCarreraConflicto: $conflictoOcupacion['nombre_carrera'],
                        ),
                    ]),
                ));
                continue;
            }

            // Filtro 4 — bloque exacto ocupado en este horario (por ID, no por traslape)
            // En este caso sí comparamos por ID porque estamos dentro del mismo horario
            // y los bloques de una misma carrera-jornada son únicos por día+hora.
            if ($bloquesOcupadosEnHorario->has($idBloque)) {
                $info = $bloquesOcupadosEnHorario->get($idBloque);
                $descartados->push(new BloqueDescartado(
                    bloque: $bloque,
                    razon:  ValidacionResultado::conConflictos([
                        ConflictoItem::bloqueOcupadoEnHorario(
                            idBloque:               $idBloque,
                            horaInicio:             $bloque->hora_inicio,
                            horaFin:                $bloque->hora_fin,
                            nombreDia:              $bloque->nombre_dia,
                            nombreSeccionConflicto: $info['seccion_descripcion'],
                        ),
                    ]),
                ));
                continue;
            }

            // Filtro 5 — traslape de ciclo (1 query por bloque que llegue hasta aquí)
            // Se pasa $horario (no $idPeriodo) para anclar al pensum
            // correcto de la carrera del horario — corrección Paso 2.
            $rCiclo = $this->conflictService->validarCicloTraslape(
                $idSeccion,
                $idBloque,
                $idHorario,
                $horario,
            );

            if ($rCiclo->tieneConflictos()) {
                $descartados->push(new BloqueDescartado(
                    bloque: $bloque,
                    razon:  $rCiclo,
                ));
                continue;
            }

            // Supera todos los filtros → candidato válido
            $validos->push($bloque);
        }

        return BloqueCandidatoResultado::crear(
            bloquesValidos:     $validos,
            bloquesDescartados: $descartados,
            contexto: [
                'id_docente'         => $idDocente,
                'id_seccion'         => $idSeccion,
                'id_horario'         => $idHorario,
                'id_carrera_jornada' => $idCarreraJornada,
                'id_periodo'         => $idPeriodo,
                'total_evaluados'    => $todosBloques->count(),
            ],
        );
    }

    // ── Queries de precarga ─────────────────────────────────────

    /**
     * Query 1 — Bloques activos de la carrera-jornada con JOIN a dia.
     * Se trae nombre_dia y orden_semana para no hacer queries extra
     * al construir mensajes de conflicto.
     */
    private function cargarBloquesCarreraJornada(int $idCarreraJornada): Collection
    {
        return DB::table('bloque_horario as bh')
            ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
            ->where('bh.id_carrera_jornada', $idCarreraJornada)
            ->where('bh.estado', 'activo')
            ->orderBy('dia.orden_semana')
            ->orderBy('bh.hora_inicio')
            ->select([
                'bh.id_bloque_horario',
                'bh.id_carrera_jornada',
                'bh.id_dia',
                'bh.hora_inicio',
                'bh.hora_fin',
                'bh.duracion_minutos',
                'bh.estado',
                'dia.nombre_dia',
                'dia.orden_semana',
            ])
            ->get()
            ->map(fn($row) => $this->rowToBloqueHorario($row));
    }

    /**
     * Query 2 — Franjas horarias bloqueadas por el docente en
     * disponibilidad_docente.
     *
     * CORRECCIÓN: Devuelve franjas {id_dia, hora_inicio, hora_fin}
     * en lugar de IDs planos, para permitir comparación por traslape
     * entre bloques de distintas carreras-jornadas.
     *
     * @return Collection<int, array{id_dia, hora_inicio, hora_fin}>
     */
    private function cargarFranjasBloqueadasDocente(int $idDocente): Collection
    {
        return DB::table('disponibilidad_docente as dd')
            ->join('bloque_horario as bh', 'dd.id_bloque_horario', '=', 'bh.id_bloque_horario')
            ->where('dd.id_docente', $idDocente)
            ->where('dd.estado', 'activo')
            ->select([
                'bh.id_dia',
                'bh.hora_inicio',
                'bh.hora_fin',
            ])
            ->get()
            ->map(fn($row) => [
                'id_dia'      => $row->id_dia,
                'hora_inicio' => $row->hora_inicio,
                'hora_fin'    => $row->hora_fin,
            ]);
    }

    /**
     * Query 3 — Franjas horarias donde el docente ya tiene clase
     * en cualquier horario activo (excepto el horario actual).
     *
     * CORRECCIÓN: Devuelve franjas {id_dia, hora_inicio, hora_fin,
     * id_horario, nombre_carrera} en lugar de IDs indexados, para
     * permitir comparación por traslape entre carreras distintas.
     *
     * CORRECCIÓN DE ESTADO: Incluye 'bloqueado' — un horario bloqueado
     * es un compromiso real del docente.
     *
     * @return Collection<int, array{id_dia, hora_inicio, hora_fin, id_horario, nombre_carrera}>
     */
    private function cargarFranjasOcupadasDocente(
        int $idDocente,
        int $idHorarioActual,
    ): Collection {
        return DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc',
                'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->join('horario as h', 'dh.id_horario', '=', 'h.id_horario')
            ->join('carrera as c', 'h.id_carrera', '=', 'c.id_carrera')
            ->join('bloque_horario as bh', 'dh.id_bloque_horario', '=', 'bh.id_bloque_horario')
            ->where('adc.id_docente', $idDocente)
            ->where('dh.estado', 'activo')
            ->where('adc.estado', 'activo')
            // Excluir el horario actual — permite reubicar sin auto-conflictar
            ->where('dh.id_horario', '!=', $idHorarioActual)
            // Todos los estados del ciclo de vida, incluido 'bloqueado'
            ->whereIn('h.id_estado_horario', function ($sub) {
                $sub->select('id_estado_horario')
                    ->from('estado_horario')
                    ->whereIn('nombre_estado', [
                        'borrador',
                        'generado',
                        'aprobado',
                        'bloqueado',   // ← CORRECCIÓN: incluido
                        'publicado',
                    ]);
            })
            ->select([
                'bh.id_dia',
                'bh.hora_inicio',
                'bh.hora_fin',
                'dh.id_horario',
                'c.nombre_carrera',
            ])
            ->get()
            ->map(fn($row) => [
                'id_dia'        => $row->id_dia,
                'hora_inicio'   => $row->hora_inicio,
                'hora_fin'      => $row->hora_fin,
                'id_horario'    => $row->id_horario,
                'nombre_carrera'=> $row->nombre_carrera,
            ]);
    }

    /**
     * Query 4 — Bloques ya asignados dentro del horario específico.
     *
     * Aquí sí comparamos por id_bloque_horario exacto porque estamos
     * dentro del mismo horario (misma carrera-jornada), donde la
     * restricción UNIQUE(id_horario, id_bloque_horario) de detalle_horario
     * garantiza que los IDs son únicos dentro del mismo conjunto de bloques.
     *
     * @return Collection<int, array>  Keyed by id_bloque_horario
     */
    private function cargarBloquesOcupadosEnHorario(int $idHorario): Collection
    {
        return DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc',
                'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->join('seccion as s', 'adc.id_seccion', '=', 's.id_seccion')
            ->join('curso as c', 's.id_curso', '=', 'c.id_curso')
            ->where('dh.id_horario', $idHorario)
            ->where('dh.estado', 'activo')
            ->select([
                'dh.id_bloque_horario',
                'c.nombre_curso',
                's.numero_seccion',
            ])
            ->get()
            ->keyBy('id_bloque_horario')
            ->map(fn($row) => [
                'seccion_descripcion' => "{$row->nombre_curso} — Sec. {$row->numero_seccion}",
            ]);
    }

    // ── Función de traslape ─────────────────────────────────────

    /**
     * Busca en una colección de franjas si alguna traslapa con
     * el intervalo candidato [horaInicio, horaFin) en el mismo día.
     *
     * Condición de traslape:
     *   franja.id_dia     = candidato.id_dia
     *   franja.hora_inicio < candidato.hora_fin
     *   franja.hora_fin    > candidato.hora_inicio
     *
     * @param  Collection<int, array{id_dia, hora_inicio, hora_fin, ...}>  $franjas
     * @param  int    $idDia
     * @param  string $horaInicio  "HH:MM:SS"
     * @param  string $horaFin     "HH:MM:SS"
     * @return array|null          La primera franja que traslapa, o null si ninguna
     */
    private function buscarTraslape(
        Collection $franjas,
        int        $idDia,
        string     $horaInicio,
        string     $horaFin,
    ): ?array {
        foreach ($franjas as $franja) {
            if (
                (int) $franja['id_dia'] === $idDia
                && $franja['hora_inicio'] < $horaFin
                && $franja['hora_fin']    > $horaInicio
            ) {
                return $franja;
            }
        }
        return null;
    }

    // ── Helper de conversión ────────────────────────────────────

    /**
     * Convierte una fila de DB::table en un objeto BloqueHorario
     * con los campos extra nombre_dia y orden_semana como atributos
     * dinámicos, disponibles en los mensajes de conflicto.
     */
    private function rowToBloqueHorario(object $row): BloqueHorario
    {
        $bloque = new BloqueHorario();
        $bloque->forceFill([
            'id_bloque_horario'  => $row->id_bloque_horario,
            'id_carrera_jornada' => $row->id_carrera_jornada,
            'id_dia'             => $row->id_dia,
            'hora_inicio'        => $row->hora_inicio,
            'hora_fin'           => $row->hora_fin,
            'duracion_minutos'   => $row->duracion_minutos,
            'estado'             => $row->estado,
        ]);
        $bloque->setAttribute('nombre_dia',   $row->nombre_dia);
        $bloque->setAttribute('orden_semana', $row->orden_semana);
        return $bloque;
    }
}
