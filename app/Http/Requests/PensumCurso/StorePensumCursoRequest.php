<?php

namespace App\Http\Requests\PensumCurso;

use Illuminate\Foundation\Http\FormRequest;

class StorePensumCursoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id_curso'          => ['required', 'integer', 'exists:curso,id_curso'],
            'ciclo_semestre'    => ['required', 'integer', 'min:1', 'max:15'],
            'bloques_semanales' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_curso.exists'              => 'El curso seleccionado no existe.',
            'ciclo_semestre.min'           => 'El ciclo debe ser al menos 1.',
            'ciclo_semestre.max'           => 'El ciclo no puede superar 15.',
            'bloques_semanales.required'   => 'Indica cuántos bloques semanales requiere el curso.',
            'bloques_semanales.min'        => 'El mínimo de bloques semanales es 1.',
            'bloques_semanales.max'        => 'El máximo de bloques semanales es 10.',
        ];
    }
}
