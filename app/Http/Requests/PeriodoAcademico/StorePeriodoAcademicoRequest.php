<?php

namespace App\Http\Requests\PeriodoAcademico;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StorePeriodoAcademicoRequest
 *
 * El frontend envía:
 *   - nombre_base (string)  → "Primer Semestre" | "Segundo Semestre" | "Escuela de Vacaciones"
 *   - fecha_inicio (date)   → el año se extrae automáticamente de aquí
 *   - (los campos anio y nombre_periodo se construyen en prepareForValidation)
 *
 * El backend construye internamente antes de validar:
 *   - anio           = year(fecha_inicio)
 *   - nombre_periodo = "{nombre_base} {anio}"  → ej: "Primer Semestre 2026"
 *
 * Esto mantiene el contrato de la BD intacto (anio y nombre_periodo siguen
 * siendo columnas independientes) sin exponer esa complejidad al usuario.
 */
class StorePeriodoAcademicoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** Opciones de nombre base permitidas */
    public const NOMBRES_BASE = [
        'Primer Semestre',
        'Segundo Semestre',
        'Escuela de Vacaciones',
    ];

    /**
     * Inyectar anio y nombre_periodo antes de la validación.
     * El controlador sigue leyendo $request->anio y $request->nombre_periodo
     * sin necesidad de cambios.
     */
    protected function prepareForValidation(): void
    {
        // Derivar anio de fecha_inicio si se proporcionó una fecha válida
        if ($this->filled('fecha_inicio') && strtotime($this->fecha_inicio)) {
            $anio = (int) date('Y', strtotime($this->fecha_inicio));
        } else {
            $anio = null;
        }

        // Construir nombre_periodo completo desde nombre_base + anio
        $nombreBase = trim((string) $this->nombre_base);
        $nombreCompleto = ($nombreBase && $anio)
            ? "{$nombreBase} {$anio}"
            : $nombreBase;

        $this->merge([
            'anio'           => $anio,
            'nombre_periodo' => $nombreCompleto,
        ]);
    }

    public function rules(): array
    {
        return [
            // nombre_base: solo valores del select estandarizado
            'nombre_base'                   => ['required', 'string', 'in:' . implode(',', self::NOMBRES_BASE)],
            // nombre_periodo: construido por prepareForValidation — max 100 chars
            'nombre_periodo'                => ['required', 'string', 'max:100'],
            // anio: derivado de fecha_inicio — no lo envía el frontend
            'anio'                          => ['required', 'integer', 'min:2000', 'max:2100'],
            // resto igual al original
            'numero_periodo'                => ['required', 'integer', 'min:1', 'max:9'],
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
            'nombre_base.required' => 'Selecciona el tipo de período académico.',
            'nombre_base.in'       => 'El período debe ser: Primer Semestre, Segundo Semestre o Escuela de Vacaciones.',
            'fecha_fin.after'      => 'La fecha de fin debe ser posterior a la fecha de inicio.',
            'estado.in'            => 'El estado debe ser: planificacion, activo, cerrado o finalizado.',
        ];
    }
}
