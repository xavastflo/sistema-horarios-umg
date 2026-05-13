<?php

namespace App\Services;

use App\Models\BloqueHorario;
use Illuminate\Support\Facades\DB;

class BloqueHorarioService
{
    /**
     * Genera bloques automáticamente para uno o varios días.
     *
     * Ejemplo vespertina 18:00-21:00, duración 90 min → genera:
     *   18:00-19:30, 19:30-21:00
     *
     * Ejemplo sábado 07:00-16:00, exclusión 13:00-14:00, duración 120 min → genera:
     *   07:00-09:00, 09:00-11:00, 11:00-13:00, 14:00-16:00
     *
     * @param int    $idCarreraJornada
     * @param array  $idsDia            IDs de días donde se crearán los bloques
     * @param string $horaInicioGeneral  "HH:MM"
     * @param string $horaFinGeneral     "HH:MM"
     * @param int    $duracionMinutos
     * @param array  $exclusiones        [['inicio'=>'HH:MM','fin'=>'HH:MM'], ...]
     * @return array  ['creados'=>[], 'omitidos'=>[], 'total_creados'=>int]
     */
    public function generarBloques(
        int    $idCarreraJornada,
        array  $idsDia,
        string $horaInicioGeneral,
        string $horaFinGeneral,
        int    $duracionMinutos,
        array  $exclusiones = []
    ): array {
        $inicioMin = $this->toMinutos($horaInicioGeneral);
        $finMin    = $this->toMinutos($horaFinGeneral);

        $rangosExcluidos = array_map(fn($e) => [
            'inicio' => $this->toMinutos($e['inicio']),
            'fin'    => $this->toMinutos($e['fin']),
        ], $exclusiones);

        $bloquesCalculados = $this->calcularBloques(
            $inicioMin,
            $finMin,
            $duracionMinutos,
            $rangosExcluidos
        );

        $creados  = [];
        $omitidos = [];

        DB::beginTransaction();
        try {
            foreach ($idsDia as $idDia) {
                foreach ($bloquesCalculados as $bloque) {
                    $horaInicio = $this->fromMinutos($bloque['inicio']);
                    $horaFin    = $this->fromMinutos($bloque['fin']);

                    $existe = BloqueHorario::where('id_carrera_jornada', $idCarreraJornada)
                        ->where('id_dia', $idDia)
                        ->where('hora_inicio', $horaInicio)
                        ->where('hora_fin', $horaFin)
                        ->exists();

                    if ($existe) {
                        $omitidos[] = [
                            'id_dia'      => $idDia,
                            'hora_inicio' => $horaInicio,
                            'hora_fin'    => $horaFin,
                            'motivo'      => 'Ya existe',
                        ];
                        continue;
                    }

                    $nuevo = BloqueHorario::create([
                        'id_carrera_jornada'  => $idCarreraJornada,
                        'id_dia'              => $idDia,
                        'hora_inicio'         => $horaInicio,
                        'hora_fin'            => $horaFin,
                        'duracion_minutos'    => $duracionMinutos,
                        'estado'              => 'activo',
                        'fecha_creacion'      => now(),
                        'fecha_actualizacion' => now(),
                    ]);

                    $creados[] = $nuevo;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return [
            'creados'       => $creados,
            'omitidos'      => $omitidos,
            'total_creados' => count($creados),
        ];
    }

    /**
     * Calcula los bloques de tiempo respetando exclusiones.
     * Devuelve array de ['inicio' => int, 'fin' => int] en minutos.
     *
     * Algoritmo:
     *   En cada iteración se busca la primera exclusión que solape con el
     *   candidato [cursor, cursor+duracion). Si existe colisión, el cursor
     *   avanza SIEMPRE al fin de esa exclusión, independientemente de si el
     *   cursor está antes, dentro o al inicio del rango excluido.
     *   Esto garantiza progreso en cada iteración y elimina todo riesgo de
     *   ciclo infinito.
     *
     * Casos cubiertos:
     *   cursor=11:00, duración=120, exclusión 12:00–13:00
     *     → candidato 11:00–13:00 solapa con 12:00–13:00
     *     → cursor avanza a 13:00 (fin exclusión) ← bug original no avanzaba aquí
     *
     *   cursor=12:30, duración=60, exclusión 12:00–13:00
     *     → cursor ya está dentro de la exclusión
     *     → cursor avanza a 13:00
     *
     *   cursor=07:00, duración=120, exclusión 13:00–14:00
     *     → candidato 07:00–09:00 no solapa → bloque válido
     */
    private function calcularBloques(
        int   $inicioMin,
        int   $finMin,
        int   $duracion,
        array $exclusiones
    ): array {
        // Ordenar exclusiones por inicio: procesar siempre la primera que aparece
        usort($exclusiones, fn($a, $b) => $a['inicio'] <=> $b['inicio']);

        $bloques = [];
        $cursor  = $inicioMin;

        // Límite de seguridad: máximo de iteraciones = minutos del rango total
        $maxIteraciones = ($finMin - $inicioMin) + 1;
        $iteracion      = 0;

        while ($cursor + $duracion <= $finMin) {

            // Guardia absoluta: nunca más iteraciones que minutos en el rango
            if (++$iteracion > $maxIteraciones) {
                break;
            }

            $candidatoFin   = $cursor + $duracion;
            $exclusionCruza = null;

            // Buscar la primera exclusión que solape con [cursor, candidatoFin)
            // Definición de solapamiento: cursor < excl.fin && candidatoFin > excl.inicio
            foreach ($exclusiones as $excl) {
                if ($cursor < $excl['fin'] && $candidatoFin > $excl['inicio']) {
                    $exclusionCruza = $excl;
                    break; // Primera exclusión ordenada por inicio
                }
            }

            if ($exclusionCruza === null) {
                // Sin conflicto → bloque válido, avanzar cursor al fin del bloque
                $bloques[] = ['inicio' => $cursor, 'fin' => $candidatoFin];
                $cursor    = $candidatoFin;
            } else {
                // Con conflicto → avanzar SIEMPRE al fin de la exclusión.
                // Cubre los tres subcasos:
                //   a) cursor < excl.inicio  (el bug original: candidato cruza por la cola)
                //   b) cursor == excl.inicio
                //   c) cursor > excl.inicio  (cursor ya dentro de la exclusión)
                // En todos los casos el cursor debe quedar en excl.fin para
                // intentar el siguiente bloque desde ahí.
                $cursor = $exclusionCruza['fin'];
            }
        }

        return $bloques;
    }

    // ── Utilidades de tiempo ────────────────────────────────────────
    private function toMinutos(string $hora): int
    {
        [$h, $m] = explode(':', $hora);
        return ((int) $h * 60) + (int) $m;
    }

    private function fromMinutos(int $minutos): string
    {
        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }
}
