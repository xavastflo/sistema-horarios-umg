<?php

namespace App\Http\Requests\BloqueHorario;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreBloqueHorarioRequest — creación individual de bloque horario.
 * Duración homologada: 50–180 min (igual que GenerarBloquesRequest).
 * Validación cruzada vía trait ValidaJornada.
 */
class StoreBloqueHorarioRequest extends FormRequest
{
    use ValidaJornada;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id_carrera_jornada' => ['required', 'integer', 'exists:carrera_jornada,id_carrera_jornada'],
            'id_dia'             => ['required', 'integer', 'exists:dia,id_dia'],
            'hora_inicio'        => ['required', 'date_format:H:i'],
            'hora_fin'           => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'duracion_minutos'   => ['required', 'integer', 'min:50', 'max:180'],
        ];
    }

    public function messages(): array
    {
        return [
            'hora_fin.after'           => 'La hora de fin debe ser posterior a la hora de inicio.',
            'hora_inicio.date_format'  => 'La hora de inicio debe tener formato HH:MM.',
            'hora_fin.date_format'     => 'La hora de fin debe tener formato HH:MM.',
            'duracion_minutos.min'     => 'La duración mínima de un bloque es 50 minutos.',
            'duracion_minutos.max'     => 'La duración máxima de un bloque es 180 minutos.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $this->aplicarValidacionJornada(
                $v,
                idCarreraJornada: (int) $this->input('id_carrera_jornada'),
                horaInicio:       (string) $this->input('hora_inicio'),
                horaFin:          (string) $this->input('hora_fin'),
                idsDia:           [$this->input('id_dia')],
                campoHoraInicio:  'hora_inicio',
                campoHoraFin:     'hora_fin',
                campoDia:         'id_dia',
            );
        });
    }
}
