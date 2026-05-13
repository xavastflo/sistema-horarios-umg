<?php

namespace App\Http\Requests\Facultad;

use Illuminate\Foundation\Http\FormRequest;

class StoreFacultadRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            // SQL oficial: varchar(100)
            'nombre_facultad'  => ['required', 'string', 'max:100', 'unique:facultad,nombre_facultad'],
            // SQL oficial: varchar(20) DEFAULT NULL — nullable en BD
            'codigo_facultad'  => ['nullable', 'string', 'max:20', 'unique:facultad,codigo_facultad', 'regex:/^[A-Z0-9_-]+$/'],
            // SQL oficial: varchar(200)
            'descripcion'      => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre_facultad.unique'  => 'Ya existe una facultad con ese nombre.',
            'codigo_facultad.unique'  => 'Ya existe una facultad con ese código.',
            'codigo_facultad.regex'   => 'El código solo puede contener letras mayúsculas, números, guiones y guiones bajos.',
        ];
    }
}
