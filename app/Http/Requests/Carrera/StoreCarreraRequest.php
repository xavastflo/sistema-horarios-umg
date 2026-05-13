<?php

namespace App\Http\Requests\Carrera;

use Illuminate\Foundation\Http\FormRequest;

class StoreCarreraRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id_facultad'    => ['required', 'integer', 'exists:facultad,id_facultad'],
            // SQL oficial: varchar(120)
            'nombre_carrera' => ['required', 'string', 'max:120'],
            'codigo_carrera' => ['required', 'string', 'max:20', 'unique:carrera,codigo_carrera', 'regex:/^[A-Z0-9_-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_facultad.exists'     => 'La facultad seleccionada no existe.',
            'codigo_carrera.unique'  => 'Ya existe una carrera con ese código.',
            'codigo_carrera.regex'   => 'El código solo puede contener letras mayúsculas, números, guiones y guiones bajos.',
        ];
    }
}
