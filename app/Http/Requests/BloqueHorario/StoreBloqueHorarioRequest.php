<?php

namespace App\Http\Requests\BloqueHorario;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreBloqueHorarioRequest
 *
 * Valida la creación manual de un bloque horario.
 * La duración debe ser múltiplo válido según la jornada (trait ValidaDuracionBloque).
 */
class StoreBloqueHorarioRequest extends FormRequest
{
    use ValidaDuracionBloque;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id_carrera_jornada' => ['required', 'integer', 'exists:carrera_jornada,id_carrera_jornada'],
            'id_dia'             => ['required', 'integer', 'exists:dia,id_dia'],
            'hora_inicio'        => ['required', 'date_format:H:i'],
            'hora_fin'           => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'duracion_minutos'   => ['required', 'integer', 'min:45', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'hora_fin.after'           => 'La hora de fin debe ser posterior a la hora de inicio.',
            'hora_inicio.date_format'  => 'La hora de inicio debe tener formato HH:MM.',
            'hora_fin.date_format'     => 'La hora de fin debe tener formato HH:MM.',
        ];
    }
}
