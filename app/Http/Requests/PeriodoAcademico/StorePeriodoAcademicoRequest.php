<?php

namespace App\Http\Requests\PeriodoAcademico;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StorePeriodoAcademicoRequest
 *
 * El frontend envía:
 *   - nombre_base (string) → "Semestres Impares" | "Semestres Pares"
 *   - fecha_inicio (date)  → el año se extrae automáticamente
 *
 * El backend construye en prepareForValidation():
 *   - anio           = year(fecha_inicio)
 *   - nombre_periodo = "{nombre_base} {anio}"  → ej: "Semestres Impares 2026"
 *
 * Lógica de ciclos del pensum (documentada, no forzada en BD):
 *   Semestres Impares  → activa ciclos IMPARES  del pensum (1, 3, 5, 7, 9, 11)
 *   Semestres Pares → activa ciclos PARES    del pensum (2, 4, 6, 8, 10, 12)
 *
 * numero_periodo: semestre de avance académico, 1 a 12.
 */
class StorePeriodoAcademicoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** Tipos de semestre permitidos (arquitectura semestral UMG) */
    public const NOMBRES_BASE = [
        'Semestres Impares',   // Enero–Junio  | ciclos impares
        'Semestres Pares',  // Julio–Noviembre | ciclos pares
    ];

    protected function prepareForValidation(): void
    {
        if ($this->filled('fecha_inicio') && strtotime($this->fecha_inicio)) {
            $anio = (int) date('Y', strtotime($this->fecha_inicio));
        } else {
            $anio = null;
        }

        $nombreBase     = trim((string) $this->nombre_base);
        $nombreCompleto = ($nombreBase && $anio) ? "{$nombreBase} {$anio}" : $nombreBase;

        $this->merge([
            'anio'           => $anio,
            'nombre_periodo' => $nombreCompleto,
        ]);
    }

    public function rules(): array
    {
        return [
            'nombre_base'                   => ['required', 'string', 'in:' . implode(',', self::NOMBRES_BASE)],
            'nombre_periodo'                => ['required', 'string', 'max:100'],
            'anio'                          => ['required', 'integer', 'min:2000', 'max:2100'],
            // Semestre 1–12: soporta hasta 12 niveles de avance (carreras largas UMG)
            'numero_periodo'                => ['required', 'integer', 'min:1', 'max:12'],
            'fecha_inicio'                  => ['required', 'date'],
            'fecha_fin'                     => ['required', 'date', 'after:fecha_inicio'],
            'fecha_limite_edicion_horarios' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'estado'                        => ['sometimes', 'required', 'in:planificacion,activo,cerrado,finalizado'],
            'es_vigente'                    => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre_base.required' => 'Selecciona el tipo de semestre.',
            'nombre_base.in'       => 'El tipo de semestre debe ser: Semestres Impares o Semestres Pares.',
            'numero_periodo.max'   => 'El semestre máximo soportado es el 12.',
            'fecha_fin.after'      => 'La fecha de fin debe ser posterior a la fecha de inicio.',
            'estado.in'            => 'El estado debe ser: planificacion, activo, cerrado o finalizado.',
        ];
    }
}
