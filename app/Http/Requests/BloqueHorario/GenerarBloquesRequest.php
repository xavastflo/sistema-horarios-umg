<?php

namespace App\Http\Requests\BloqueHorario;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GenerarBloquesRequest — generación automática de bloques horarios.
 * Duración homologada: 50–180 min (igual que StoreBloqueHorarioRequest).
 * Validación cruzada vía trait ValidaJornada — cierra la brecha de seguridad
 * que dejaba el endpoint POST /api/bloques-horario/generar desprotegido.
 */
class GenerarBloquesRequest extends FormRequest
{
    use ValidaJornada;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id_carrera_jornada'   => ['required', 'integer', 'exists:carrera_jornada,id_carrera_jornada'],
            'ids_dia'              => ['required', 'array', 'min:1'],
            'ids_dia.*'            => ['integer', 'exists:dia,id_dia'],
            'hora_inicio_general'  => ['required', 'date_format:H:i'],
            'hora_fin_general'     => ['required', 'date_format:H:i', 'after:hora_inicio_general'],
            'duracion_minutos'     => ['required', 'integer', 'min:50', 'max:180'],
            'exclusiones'          => ['nullable', 'array'],
            'exclusiones.*.inicio' => ['required_with:exclusiones', 'date_format:H:i'],
            'exclusiones.*.fin'    => ['required_with:exclusiones', 'date_format:H:i', 'after:exclusiones.*.inicio'],
        ];
    }

    public function messages(): array
    {
        return [
            'hora_fin_general.after'           => 'La hora de fin debe ser posterior a la hora de inicio.',
            'hora_inicio_general.date_format'  => 'Use formato HH:MM (ej. 07:00).',
            'hora_fin_general.date_format'     => 'Use formato HH:MM (ej. 16:00).',
            'duracion_minutos.min'             => 'La duración mínima de un bloque es 50 minutos.',
            'duracion_minutos.max'             => 'La duración máxima de un bloque es 180 minutos.',
            'ids_dia.min'                      => 'Debes seleccionar al menos un día.',
        ];
    }

    /**
     * Validación cruzada: aplica reglas de jornada al rango horario general
     * y a cada uno de los días seleccionados.
     * Si algún día viola la regla de la jornada → 422 con mensaje preciso.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $this->aplicarValidacionJornada(
                $v,
                idCarreraJornada: (int) $this->input('id_carrera_jornada'),
                horaInicio:       (string) $this->input('hora_inicio_general'),
                horaFin:          (string) $this->input('hora_fin_general'),
                idsDia:           (array) $this->input('ids_dia', []),
                campoHoraInicio:  'hora_inicio_general',
                campoHoraFin:     'hora_fin_general',
                campoDia:         'ids_dia',
            );
        });
    }
}
