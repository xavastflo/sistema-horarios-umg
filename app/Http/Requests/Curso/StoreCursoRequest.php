<?php

namespace App\Http\Requests\Curso;

use Illuminate\Foundation\Http\FormRequest;

class StoreCursoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'codigo_curso' => ['required', 'string', 'max:20', 'unique:curso,codigo_curso'],
            'nombre_curso' => ['required', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo_curso.unique' => 'Ya existe un curso con ese código.',
        ];
    }
}
