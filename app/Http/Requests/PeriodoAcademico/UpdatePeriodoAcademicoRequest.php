<?php

namespace App\Http\Requests\PeriodoAcademico;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdatePeriodoAcademicoRequest
 *
 * Misma lógica que StorePeriodoAcademicoRequest pero con campos opcionales.
 * Si se envía nombre_base, numero_periodo se recalcula automáticamente.
 * El usuario nunca puede enviar numero_periodo manualmente como 1–12.
 */
class UpdatePeriodoAcademicoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public const NOMBRES_BASE = [
        'Semestres Impares',
        'Semestres Pares',
    ];

    public const NUMERO_PERIODO_MAP = [
        'Semestres Impares' => 1,
        'Semestres Pares'   => 2,
    ];

    protected function prepareForValidation(): void
    {
        // Solo recalcular si se envía nombre_base o fecha_inicio
        $periodo = $this->route('periodo') ?? $this->route('id');

        if ($this->filled('nombre_base')) {
            $nombreBase = trim((string) $this->nombre_base);

            // Derivar anio desde fecha_inicio si viene, si no usar el del período actual
            if ($this->filled('fecha_inicio') && strtotime($this->fecha_inicio)) {
                $anio = (int) date('Y', strtotime($this->fecha_inicio));
            } elseif ($periodo && $periodo->fecha_inicio) {
                $anio = (int) date('Y', strtotime($periodo->fecha_inicio));
            } else {
                $anio = null;
            }

            $nombreCompleto = ($nombreBase && $anio) ? "{$nombreBase} {$anio}" : $nombreBase;
            $numeroPeriodo  = self::NUMERO_PERIODO_MAP[$nombreBase] ?? null;

            $this->merge([
                'anio'           => $anio,
                'nombre_periodo' => $nombreCompleto,
                'numero_periodo' => $numeroPeriodo,
            ]);

        } elseif ($this->filled('fecha_inicio') && $periodo) {
            // Solo cambia la fecha — reconstruir nombre_periodo conservando el prefijo actual
            // y preservar numero_periodo derivándolo del prefijo actual del período
            if (strtotime($this->fecha_inicio)) {
                $anio    = (int) date('Y', strtotime($this->fecha_inicio));
                $prefijo = preg_replace('/\s+\d{4}$/', '', $periodo->nombre_periodo ?? '');

                // Recalcular numero_periodo desde el prefijo actual del período
                // para que siga siendo 1 (Impares) o 2 (Pares) tras el cambio de fecha
                $numeroPeriodo = self::NUMERO_PERIODO_MAP[$prefijo]
                    ?? self::NUMERO_PERIODO_MAP[$periodo->nombre_base ?? '']
                    ?? $periodo->numero_periodo;  // fallback: conservar el valor actual de BD

                $this->merge([
                    'anio'           => $anio,
                    'nombre_periodo' => "{$prefijo} {$anio}",
                    'numero_periodo' => $numeroPeriodo,
                ]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'nombre_base'                   => ['sometimes', 'required', 'string', 'in:' . implode(',', self::NOMBRES_BASE)],
            'nombre_periodo'                => ['sometimes', 'required', 'string', 'max:100'],
            'anio'                          => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            // Solo 1 o 2 — calculado automáticamente, nunca manual
            'numero_periodo'                => ['sometimes', 'required', 'integer', 'in:1,2'],
            'fecha_inicio'                  => ['sometimes', 'required', 'date'],
            'fecha_fin'                     => ['sometimes', 'required', 'date', 'after:fecha_inicio'],
            'fecha_limite_edicion_horarios' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'estado'                        => ['sometimes', 'required', 'in:planificacion,activo,cerrado,finalizado'],
            'es_vigente'                    => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre_base.in'    => 'El tipo de semestre debe ser: Semestres Impares o Semestres Pares.',
            'numero_periodo.in' => 'El período debe ser 1 (Semestres Impares) o 2 (Semestres Pares).',
            'estado.in'         => 'El estado debe ser: planificacion, activo, cerrado o finalizado.',
        ];
    }
}
