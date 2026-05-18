<?php

namespace App\Http\Requests\PeriodoAcademico;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdatePeriodoAcademicoRequest
 *
 * En edición el frontend puede enviar nombre_base y/o fecha_inicio.
 * Si se envía nombre_base, se reconstruye nombre_periodo.
 * Si se envía fecha_inicio (nueva), se recalcula anio.
 * Si solo se envía uno de los dos, se usa el valor del período existente
 * para construir el nombre completo.
 */
class UpdatePeriodoAcademicoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public const NOMBRES_BASE = [
        'Primer Semestre',
        'Segundo Semestre',
        'Escuela de Vacaciones',
    ];

    protected function prepareForValidation(): void
    {
        $periodo = $this->route('periodo'); // modelo del route model binding

        // Derivar anio: de fecha_inicio entrante o del período existente
        if ($this->filled('fecha_inicio') && strtotime($this->fecha_inicio)) {
            $anio = (int) date('Y', strtotime($this->fecha_inicio));
        } elseif ($periodo) {
            $anio = $periodo->anio;
        } else {
            $anio = null;
        }

        // Reconstruir nombre_periodo si viene nombre_base
        if ($this->filled('nombre_base')) {
            $nombreBase     = trim((string) $this->nombre_base);
            $nombreCompleto = $anio ? "{$nombreBase} {$anio}" : $nombreBase;
            $this->merge([
                'anio'           => $anio,
                'nombre_periodo' => $nombreCompleto,
            ]);
        } elseif ($this->filled('fecha_inicio') && $periodo) {
            // Solo cambió la fecha → actualizar el año en el nombre existente
            // Extraer prefijo del nombre actual (todo antes del año)
            $nombreActual = $periodo->nombre_periodo ?? '';
            $prefijo      = preg_replace('/\s+\d{4}$/', '', $nombreActual);
            $this->merge([
                'anio'           => $anio,
                'nombre_periodo' => "{$prefijo} {$anio}",
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'nombre_base'                   => ['sometimes', 'required', 'string', 'in:' . implode(',', self::NOMBRES_BASE)],
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
            'nombre_base.in' => 'El período debe ser: Primer Semestre, Segundo Semestre o Escuela de Vacaciones.',
            'estado.in'      => 'El estado debe ser: planificacion, activo, cerrado o finalizado.',
        ];
    }
}
