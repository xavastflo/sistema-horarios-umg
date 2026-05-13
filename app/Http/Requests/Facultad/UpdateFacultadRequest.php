<?php

namespace App\Http\Requests\Facultad;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFacultadRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('facultad');

        return [
            // SQL oficial: varchar(100)
            'nombre_facultad' => ['sometimes', 'required', 'string', 'max:100', "unique:facultad,nombre_facultad,{$id},id_facultad"],
            // SQL oficial: varchar(20) nullable
            'codigo_facultad' => ['sometimes', 'nullable', 'string', 'max:20', "unique:facultad,codigo_facultad,{$id},id_facultad", 'regex:/^[A-Z0-9_-]+$/'],
            // SQL oficial: varchar(200)
            'descripcion'     => ['nullable', 'string', 'max:200'],
            // SQL oficial: ENUM('activo','inactivo')
            'estado'          => ['sometimes', 'required', 'in:activo,inactivo'],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.in' => 'El estado debe ser activo o inactivo.',
        ];
    }
}
