<?php

namespace App\Http\Requests\Facultad;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFacultadRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id       = $this->route('facultad');
        // Si el cliente envía id_centro_educativo usamos el nuevo, si no el actual
        $idCentro = $this->input(
            'id_centro_educativo',
            \App\Models\Facultad::find($id)?->id_centro_educativo
        );

        return [
            // Permitir cambio de sede en edición
            'id_centro_educativo' => ['sometimes', 'integer', 'exists:centro_educativo,id_centro_educativo'],
            // Unicidad compuesta ignorando el registro actual
            'nombre_facultad'     => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('facultad')
                    ->where('id_centro_educativo', $idCentro)
                    ->ignore($id, 'id_facultad'),
            ],
            'codigo_facultad'     => [
                'sometimes', 'nullable', 'string', 'max:20',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('facultad')
                    ->where('id_centro_educativo', $idCentro)
                    ->ignore($id, 'id_facultad')
                    ->whereNotNull('codigo_facultad'),
            ],
            'descripcion'         => ['nullable', 'string', 'max:200'],
            'estado'              => ['sometimes', 'required', 'in:activo,inactivo'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_centro_educativo.exists' => 'La sede seleccionada no existe.',
            'nombre_facultad.unique'     => 'Ya existe una facultad con ese nombre en esta sede.',
            'codigo_facultad.unique'     => 'Ya existe una facultad con ese código en esta sede.',
            'codigo_facultad.regex'      => 'El código solo puede contener letras mayúsculas, números, guiones y guiones bajos.',
            'estado.in'                  => 'El estado debe ser activo o inactivo.',
        ];
    }
}
