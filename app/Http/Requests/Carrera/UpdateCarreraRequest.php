<?php

namespace App\Http\Requests\Carrera;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCarreraRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('carrera');

        return [
            'id_facultad'    => ['sometimes', 'required', 'integer', 'exists:facultad,id_facultad'],
            // SQL oficial: varchar(120)
            'nombre_carrera' => ['sometimes', 'required', 'string', 'max:120'],
            'codigo_carrera' => [
                'sometimes', 'required', 'string', 'max:20',
                "unique:carrera,codigo_carrera,{$id},id_carrera",
                'regex:/^[A-Z0-9_-]+$/',
            ],
            // SQL oficial: ENUM('activo','inactivo')
            'estado'         => ['sometimes', 'required', 'in:activo,inactivo'],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.in' => 'El estado debe ser activo o inactivo.',
        ];
    }
}
