<?php

namespace App\Http\Requests\Docente;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDocenteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('docente');

        return [
            // SQL oficial: varchar(20) nullable
            'codigo_docente' => ['sometimes', 'nullable', 'string', 'max:20', "unique:docente,codigo_docente,{$id},id_docente"],
            'prioridad'      => ['sometimes', 'required', 'integer', 'in:1,2,3'],
            // SQL oficial: ENUM('activo','inactivo')
            'estado'         => ['sometimes', 'required', 'in:activo,inactivo'],
        ];
    }

    public function messages(): array
    {
        return [
            'prioridad.in'   => 'La prioridad debe ser 1 (alta), 2 (media) o 3 (baja).',
            'estado.in'      => 'El estado debe ser activo o inactivo.',
            'codigo_docente.max' => 'El código no puede tener más de 20 caracteres.',
        ];
    }
}
