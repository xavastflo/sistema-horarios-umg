<?php

namespace App\Http\Requests\DisponibilidadDocente;

use Illuminate\Foundation\Http\FormRequest;

class StoreDisponibilidadRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id_bloque_horario' => ['required', 'integer', 'exists:bloque_horario,id_bloque_horario'],
            'observacion'       => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_bloque_horario.exists' => 'El bloque horario seleccionado no existe.',
        ];
    }
}
