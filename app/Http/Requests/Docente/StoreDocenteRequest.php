<?php

namespace App\Http\Requests\Docente;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocenteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id_usuario'     => [
                'required',
                'integer',
                'exists:usuario,id_usuario',
                'unique:docente,id_usuario',
            ],
            // SQL oficial: varchar(20) DEFAULT NULL — opcional al crear
            'codigo_docente' => ['nullable', 'string', 'max:20', 'unique:docente,codigo_docente'],
            // Prioridad: int, valores válidos 1|2|3, DEFAULT 3 si no se envía
            'prioridad'      => ['sometimes', 'required', 'integer', 'in:1,2,3'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_usuario.exists'       => 'El usuario seleccionado no existe.',
            'id_usuario.unique'       => 'El usuario ya está registrado como docente.',
            'codigo_docente.unique'   => 'El código de docente ya está en uso.',
            'codigo_docente.max'      => 'El código no puede tener más de 20 caracteres.',
            'prioridad.in'            => 'La prioridad debe ser 1 (alta), 2 (media) o 3 (baja).',
        ];
    }
}
