<?php

namespace App\Http\Requests\PeriodoAcademico;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StorePeriodoAcademicoRequest
 *
 * El frontend envía:
 *   - nombre_base     (string)  → "Semestres Impares" | "Semestres Pares"
 *   - numero_periodo  (int)     → calculado automáticamente por el frontend:
 *                                 Semestres Impares → 1 | Semestres Pares → 2
 *   - fecha_inicio    (date)    → el año se extrae automáticamente
 *
 * El backend construye en prepareForValidation():
 *   - anio           = year(fecha_inicio)
 *   - nombre_periodo = "{nombre_base} {anio}"  → ej: "Semestres Impares 2026"
 *   - numero_periodo = derivado de nombre_base (1 o 2) — se sobreescribe
 *                      para garantizar coherencia aunque el frontend lo envíe
 *
 * Regla de negocio:
 *   Semestres Impares → numero_periodo = 1 → UNIQUE(anio, 1) por año
 *   Semestres Pares   → numero_periodo = 2 → UNIQUE(anio, 2) por año
 */
class StorePeriodoAcademicoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** Tipos de semestre permitidos */
    public const NOMBRES_BASE = [
        'Semestres Impares',   // Enero–Junio    | ciclos impares (1,3,5,7,9,11)
        'Semestres Pares',     // Julio–Noviembre | ciclos pares   (2,4,6,8,10,12)
    ];

    /** Mapa nombre_base → numero_periodo (fuente de verdad) */
    public const NUMERO_PERIODO_MAP = [
        'Semestres Impares' => 1,
        'Semestres Pares'   => 2,
    ];

    protected function prepareForValidation(): void
    {
        // 1. Derivar anio desde fecha_inicio
        if ($this->filled('fecha_inicio') && strtotime($this->fecha_inicio)) {
            $anio = (int) date('Y', strtotime($this->fecha_inicio));
        } else {
            $anio = null;
        }

        // 2. Construir nombre_periodo desde nombre_base + anio
        $nombreBase     = trim((string) $this->nombre_base);
        $nombreCompleto = ($nombreBase && $anio) ? "{$nombreBase} {$anio}" : $nombreBase;

        // 3. Calcular numero_periodo desde nombre_base — sobreescribe lo que envíe
        //    el frontend para garantizar coherencia (nunca "Semestres Pares" con 1)
        $numeroPeriodo = self::NUMERO_PERIODO_MAP[$nombreBase] ?? $this->numero_periodo;

        $this->merge([
            'anio'           => $anio,
            'nombre_periodo' => $nombreCompleto,
            'numero_periodo' => $numeroPeriodo,
        ]);
    }

    public function rules(): array
    {
        return [
            'nombre_base'                   => ['required', 'string', 'in:' . implode(',', self::NOMBRES_BASE)],
            'nombre_periodo'                => ['required', 'string', 'max:100'],
            'anio'                          => ['required', 'integer', 'min:2000', 'max:2100'],
            // Solo 1 (Impares) o 2 (Pares) — calculado automáticamente
            'numero_periodo'                => ['required', 'integer', 'in:1,2'],
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
            'numero_periodo.in'    => 'El período debe ser 1 (Semestres Impares) o 2 (Semestres Pares).',
            'estado.in'            => 'El estado debe ser: planificacion, activo, cerrado o finalizado.',
        ];
    }
}
