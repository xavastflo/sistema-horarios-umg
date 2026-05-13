<?php

namespace App\Http\Requests\Curso;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCursoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('curso');

        return [
            'codigo_curso' => ['sometimes', 'required', 'string', 'max:20', "unique:curso,codigo_curso,{$id},id_curso"],
            'nombre_curso' => ['sometimes', 'required', 'string', 'max:120'],
            'estado'       => ['sometimes', 'required', 'in:activo,inactivo'],
        ];
    }
}
