<?php

namespace App\Http\Requests\BloqueHorario;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GenerarBloquesRequest
 *
 * Valida la generación automática de bloques por rango horario.
 * Sobreescribe campoHoraInicio() y campoHoraFin() porque los nombres
 * de campo son hora_inicio_general / hora_fin_general, no hora_inicio / hora_fin.
 */
class GenerarBloquesRequest extends FormRequest
{
    use ValidaDuracionBloque;

    public function authorize(): bool { return true; }

    // Sobreescribir para que withValidator() use los campos correctos
    protected function campoHoraInicio(): string { return 'hora_inicio_general'; }
    protected function campoHoraFin(): string    { return 'hora_fin_general'; }

    public function rules(): array
    {
        return [
            'id_carrera_jornada'  => ['required', 'integer', 'exists:carrera_jornada,id_carrera_jornada'],
            // ids_dia: arreglo de días en los que se generan bloques
            'ids_dia'             => ['required', 'array', 'min:1'],
            'ids_dia.*'           => ['integer', 'exists:dia,id_dia'],
            // Hora de inicio del rango general (ej. "07:00")
            'hora_inicio_general' => ['required', 'date_format:H:i'],
            // Hora de fin del rango general (ej. "16:00")
            'hora_fin_general'    => ['required', 'date_format:H:i', 'after:hora_inicio_general'],
            // Duración de cada bloque en minutos
            // min:45 porque matutina y vespertina permiten bloques de 45 min
            'duracion_minutos'    => [
                'required',
                'integer',
                'min:45',
                'max:' . config('academico.bloque_duracion_maxima', 180),
            ],
            // Rangos a excluir (ej. almuerzo "13:00-14:00")
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
            'duracion_minutos.min'             => 'La duración mínima de un bloque es 45 minutos.',
        ];
    }
    
    protected function validaDuracionComoRangoTotal(): bool
    {
        return true;
    }
}
