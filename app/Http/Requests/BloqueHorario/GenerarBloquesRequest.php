<?php

namespace App\Http\Requests\BloqueHorario;

use Illuminate\Foundation\Http\FormRequest;

class GenerarBloquesRequest extends FormRequest
{
    public function authorize(): bool { return true; }

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
            // Duración de cada bloque en minutos (ej. 90, 120)
            'duracion_minutos'    => [
                'required',
                'integer',
                'min:' . config('academico.bloque_duracion_minima', 50),
                'max:' . config('academico.bloque_duracion_maxima', 180),
            ],
            // Rangos a excluir (ej. almuerzo "13:00-14:00")
            // Arreglo de objetos con inicio y fin
            'exclusiones'         => ['nullable', 'array'],
            'exclusiones.*.inicio' => ['required_with:exclusiones', 'date_format:H:i'],
            'exclusiones.*.fin'    => ['required_with:exclusiones', 'date_format:H:i', 'after:exclusiones.*.inicio'],
        ];
    }

    public function messages(): array
    {
        return [
            'hora_fin_general.after'        => 'La hora de fin debe ser posterior a la hora de inicio.',
            'hora_inicio_general.date_format' => 'Use formato HH:MM (ej. 07:00).',
            'hora_fin_general.date_format'    => 'Use formato HH:MM (ej. 16:00).',
            'duracion_minutos.min'            => 'La duración mínima de un bloque es ' . config('academico.bloque_duracion_minima', 50) . ' minutos.',
        ];
    }
}
