<?php

namespace App\Http\Requests\Facultad;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFacultadRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $idCentro = $this->input('id_centro_educativo');

        return [
            // QA: campo nuevo obligatorio — FK a sede
            'id_centro_educativo' => ['required', 'integer', 'exists:centro_educativo,id_centro_educativo'],
            // QA: unicidad compuesta (sede + nombre), no global
            'nombre_facultad'     => [
                'required', 'string', 'max:100',
                Rule::unique('facultad')->where('id_centro_educativo', $idCentro),
            ],
            'codigo_facultad'     => [
                'nullable', 'string', 'max:20',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('facultad')->where('id_centro_educativo', $idCentro)
                    ->whereNotNull('codigo_facultad'),
            ],
            'descripcion'         => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_centro_educativo.required' => 'Debes seleccionar la sede a la que pertenece la facultad.',
            'id_centro_educativo.exists'   => 'La sede seleccionada no existe.',
            'nombre_facultad.unique'       => 'Ya existe una facultad con ese nombre en esta sede.',
            'codigo_facultad.unique'       => 'Ya existe una facultad con ese código en esta sede.',
            'codigo_facultad.regex'        => 'El código solo puede contener letras mayúsculas, números, guiones y guiones bajos.',
        ];
    }
}
