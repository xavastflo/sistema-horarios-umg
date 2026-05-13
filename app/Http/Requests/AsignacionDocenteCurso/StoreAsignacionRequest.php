<?php

namespace App\Http\Requests\AsignacionDocenteCurso;

use Illuminate\Foundation\Http\FormRequest;

class StoreAsignacionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id_docente' => ['required', 'integer', 'exists:docente,id_docente'],
            // id_seccion se toma de la ruta: POST /api/secciones/{seccion}/asignacion
        ];
    }

    public function messages(): array
    {
        return [
            'id_docente.exists' => 'El docente seleccionado no existe.',
        ];
    }
}
