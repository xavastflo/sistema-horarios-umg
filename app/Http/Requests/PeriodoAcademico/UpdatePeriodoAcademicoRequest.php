<?php

namespace App\Http\Requests\PeriodoAcademico;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePeriodoAcademicoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nombre_periodo'                => ['sometimes', 'required', 'string', 'max:100'],
            'anio'                          => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'numero_periodo'                => ['sometimes', 'required', 'integer', 'min:1', 'max:9'],
            'fecha_inicio'                  => ['sometimes', 'required', 'date'],
            'fecha_fin'                     => ['sometimes', 'required', 'date', 'after:fecha_inicio'],
            'fecha_limite_edicion_horarios' => ['nullable', 'date'],
            'estado'                        => ['sometimes', 'required', 'in:planificacion,activo,cerrado,finalizado'],
            'es_vigente'                    => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.in' => 'El estado debe ser: planificacion, activo, cerrado o finalizado.',
        ];
    }
}
