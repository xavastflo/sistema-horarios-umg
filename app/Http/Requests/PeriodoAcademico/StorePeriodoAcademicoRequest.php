<?php

namespace App\Http\Requests\PeriodoAcademico;

use Illuminate\Foundation\Http\FormRequest;

class StorePeriodoAcademicoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            // SQL oficial: varchar(100)
            'nombre_periodo'                => ['required', 'string', 'max:100'],
            // SQL oficial: year(4) — número de año
            'anio'                          => ['required', 'integer', 'min:2000', 'max:2100'],
            // SQL oficial: tinyint UNSIGNED — número de período dentro del año
            'numero_periodo'                => ['required', 'integer', 'min:1', 'max:9'],
            'fecha_inicio'                  => ['required', 'date'],
            'fecha_fin'                     => ['required', 'date', 'after:fecha_inicio'],
            'fecha_limite_edicion_horarios' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            // SQL oficial: ENUM 4 valores
            'estado'                        => ['sometimes', 'required', 'in:planificacion,activo,cerrado,finalizado'],
            'es_vigente'                    => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha_fin.after'               => 'La fecha de fin debe ser posterior a la fecha de inicio.',
            'estado.in'                     => 'El estado debe ser: planificacion, activo, cerrado o finalizado.',
            // UNIQUE(anio, numero_periodo) se valida en el controller para mensaje claro
        ];
    }
}
