<?php

namespace App\Http\Requests\Pensum;

use Illuminate\Foundation\Http\FormRequest;

class StorePensumRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id_carrera'           => ['required', 'integer', 'exists:carrera,id_carrera'],
            'id_periodo_academico' => ['required', 'integer', 'exists:periodo_academico,id_periodo_academico'],
            'nombre_pensum'        => ['required', 'string', 'max:120'],
            'codigo_pensum'        => ['required', 'string', 'max:20', 'unique:pensum,codigo_pensum'],
            'descripcion'          => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_carrera.exists'           => 'La carrera seleccionada no existe.',
            'id_periodo_academico.exists' => 'El período académico seleccionado no existe.',
            'codigo_pensum.unique'        => 'Ya existe un pensum con ese código.',
        ];
    }
}
