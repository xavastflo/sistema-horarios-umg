<?php

namespace App\Http\Requests\PeriodoAcademico;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePeriodoAcademicoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public const NOMBRES_BASE = [
        'Semestres Impares',
        'Semestres Pares',
    ];

    protected function prepareForValidation(): void
    {
        $periodo = $this->route('periodo');

        if ($this->filled('fecha_inicio') && strtotime($this->fecha_inicio)) {
            $anio = (int) date('Y', strtotime($this->fecha_inicio));
        } elseif ($periodo) {
            $anio = $periodo->anio;
        } else {
            $anio = null;
        }

        if ($this->filled('nombre_base')) {
            $nombreBase     = trim((string) $this->nombre_base);
            $nombreCompleto = $anio ? "{$nombreBase} {$anio}" : $nombreBase;
            $this->merge(['anio' => $anio, 'nombre_periodo' => $nombreCompleto]);
        } elseif ($this->filled('fecha_inicio') && $periodo) {
            $prefijo = preg_replace('/\s+\d{4}$/', '', $periodo->nombre_periodo ?? '');
            $this->merge(['anio' => $anio, 'nombre_periodo' => "{$prefijo} {$anio}"]);
        }
    }

    public function rules(): array
    {
        return [
            'nombre_base'                   => ['sometimes', 'required', 'string', 'in:' . implode(',', self::NOMBRES_BASE)],
            'nombre_periodo'                => ['sometimes', 'required', 'string', 'max:100'],
            'anio'                          => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'numero_periodo'                => ['sometimes', 'required', 'integer', 'min:1', 'max:12'],
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
            'nombre_base.in'     => 'El tipo de semestre debe ser: Semestres Impares o Semestres Pares.',
            'numero_periodo.max' => 'El semestre máximo soportado es el 12.',
            'estado.in'          => 'El estado debe ser: planificacion, activo, cerrado o finalizado.',
        ];
    }
}
