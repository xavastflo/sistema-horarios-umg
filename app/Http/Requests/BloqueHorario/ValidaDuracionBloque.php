<?php

namespace App\Http\Requests\BloqueHorario;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\DB;

/**
 * Trait ValidaDuracionBloque
 *
 * Validaciones aplicadas en withValidator():
 *
 * A) COHERENCIA DE DURACIÓN
 *
 *    - Para creación manual de un bloque:
 *      duracion_minutos debe coincidir exactamente con hora_fin - hora_inicio.
 *
 *    - Para generación automática de varios bloques:
 *      duracion_minutos representa la duración de cada bloque, mientras que
 *      hora_inicio_general y hora_fin_general representan el rango total.
 *      En ese caso, el rango total debe ser divisible entre duracion_minutos.
 *
 * B) MÚLTIPLO SEGÚN JORNADA
 *    matutina / vespertina → múltiplo de 45 min
 *    fin_de_semana         → múltiplo de 60 min
 *
 * Ambas validaciones solo actúan si los campos base pasaron rules().
 * Si algún campo básico ya tiene error, no se acumula un segundo mensaje.
 *
 * Sobreescribir campoHoraInicio() / campoHoraFin() en requests que usen
 * nombres de campo distintos (ej. hora_inicio_general).
 *
 * Sobreescribir validarComoRangoTotal() en requests donde hora_inicio/hora_fin
 * representan un rango general para generar varios bloques.
 */
trait ValidaDuracionBloque
{
    /** Campo de duración en el request. */
    protected function campoDuracion(): string
    {
        return 'duracion_minutos';
    }

    /** Campo id_carrera_jornada en el request. */
    protected function campoCarreraJornada(): string
    {
        return 'id_carrera_jornada';
    }

    /** Campo hora_inicio en el request. */
    protected function campoHoraInicio(): string
    {
        return 'hora_inicio';
    }

    /** Campo hora_fin en el request. */
    protected function campoHoraFin(): string
    {
        return 'hora_fin';
    }

    /**
     * Por defecto, el request representa un bloque individual.
     *
     * StoreBloqueHorarioRequest:
     *   hora_inicio / hora_fin = rango del bloque individual
     *   duracion_minutos debe coincidir exactamente con ese rango.
     *
     * GenerarBloquesRequest debe sobrescribir este método y devolver true:
     *   hora_inicio_general / hora_fin_general = rango total
     *   duracion_minutos = duración de cada bloque generado.
     */
    protected function validaDuracionComoRangoTotal(): bool
    {
        return false;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {

            $campoDur  = $this->campoDuracion();
            $campoJorn = $this->campoCarreraJornada();
            $campoHi   = $this->campoHoraInicio();
            $campoHf   = $this->campoHoraFin();

            // No acumular errores si los campos base ya fallaron.
            if ($v->errors()->hasAny([$campoDur, $campoJorn, $campoHi, $campoHf])) {
                return;
            }

            $duracion         = (int) $this->input($campoDur);
            $idCarreraJornada = (int) $this->input($campoJorn);
            $horaInicio       = $this->input($campoHi);
            $horaFin          = $this->input($campoHf);

            if ($duracion <= 0 || $idCarreraJornada <= 0 || ! $horaInicio || ! $horaFin) {
                return;
            }

            // ── Validación A: coherencia del rango horario ───────────────────────
            $minutosReales = $this->calcularMinutos($horaInicio, $horaFin);

            if ($minutosReales !== null) {
                if ($this->validaDuracionComoRangoTotal()) {
                    // Generación automática:
                    // El rango total debe dividirse exactamente en bloques de duración indicada.
                    if ($minutosReales % $duracion !== 0) {
                        $v->errors()->add(
                            $campoDur,
                            "La duración indicada ({$duracion} min) no divide exactamente el rango horario "
                            . "{$horaInicio}–{$horaFin} ({$minutosReales} min reales)."
                        );

                        return;
                    }
                } elseif ($minutosReales !== $duracion) {
                    // Creación manual:
                    // La duración indicada debe coincidir con el rango del bloque individual.
                    $v->errors()->add(
                        $campoDur,
                        "La duración indicada ({$duracion} min) no coincide con el rango horario "
                        . "{$horaInicio}–{$horaFin} ({$minutosReales} min reales)."
                    );

                    return;
                }
            }

            // ── Validación B: múltiplo según jornada ─────────────────────────────
            $nombreJornada = DB::table('carrera_jornada as cj')
                ->join('jornada as j', 'cj.id_jornada', '=', 'j.id_jornada')
                ->where('cj.id_carrera_jornada', $idCarreraJornada)
                ->value('j.nombre_jornada');

            if (! $nombreJornada) {
                return; // exists en rules() ya lo reportó.
            }

            $multiplo = match ($nombreJornada) {
                'matutina'      => 45,
                'vespertina'    => 45,
                'fin_de_semana' => 60,
                default         => null, // jornada futura: no aplicar regla
            };

            if ($multiplo === null) {
                return;
            }

            if ($duracion % $multiplo !== 0) {
                $etiqueta = match ($nombreJornada) {
                    'matutina'      => 'Matutina',
                    'vespertina'    => 'Vespertina',
                    'fin_de_semana' => 'Fin de semana',
                    default         => $nombreJornada,
                };

                $ejemplos = implode(', ', array_map(
                    fn ($n) => ($multiplo * $n) . ' min',
                    range(1, min(4, (int) floor(300 / $multiplo)))
                ));

                $v->errors()->add(
                    $campoDur,
                    "La duración del bloque no es válida para la jornada {$etiqueta}. "
                    . "Debe ser múltiplo de {$multiplo} minutos (ej: {$ejemplos})."
                );
            }
        });
    }

    /**
     * Calcula la diferencia en minutos entre dos horas en formato HH:MM.
     * Retorna null si alguna hora no es válida o si la diferencia no es positiva.
     */
    private function calcularMinutos(string $horaInicio, string $horaFin): ?int
    {
        $partsI = explode(':', $horaInicio);
        $partsF = explode(':', $horaFin);

        if (count($partsI) < 2 || count($partsF) < 2) {
            return null;
        }

        $minutosI = ((int) $partsI[0]) * 60 + (int) $partsI[1];
        $minutosF = ((int) $partsF[0]) * 60 + (int) $partsF[1];
        $diff     = $minutosF - $minutosI;

        return $diff > 0 ? $diff : null;
    }
}
