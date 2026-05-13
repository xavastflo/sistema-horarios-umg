<?php

namespace App\Http\Requests\Seccion;

use Illuminate\Foundation\Http\FormRequest;

class StoreSeccionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id_curso'             => ['required', 'integer', 'exists:curso,id_curso'],
            'id_periodo_academico' => ['required', 'integer', 'exists:periodo_academico,id_periodo_academico'],
            // varchar(10): puede ser "A", "B", "01", etc.
            'numero_seccion'       => ['required', 'string', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_curso.exists'             => 'El curso seleccionado no existe.',
            'id_periodo_academico.exists' => 'El período académico seleccionado no existe.',
        ];
    }
}
