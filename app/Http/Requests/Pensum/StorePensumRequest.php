<?php

namespace App\Http\Requests\Pensum;

use Illuminate\Foundation\Http\FormRequest;

class StorePensumRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id_carrera'           => ['required', 'integer', 'exists:carrera,id_carrera'],
            'anio_inicio_vigencia' => ['required', 'integer', 'min:2000', 'max:2100'],
            'anio_fin_vigencia'    => ['nullable', 'integer', 'min:2000', 'max:2100', 'gte:anio_inicio_vigencia'],
            'nombre_pensum'        => ['required', 'string', 'max:120'],
            'codigo_pensum'        => ['required', 'string', 'max:20', 'unique:pensum,codigo_pensum'],
            'descripcion'          => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_carrera.exists'            => 'La carrera seleccionada no existe.',
            'anio_inicio_vigencia.required' => 'El año de inicio de vigencia es obligatorio.',
            'anio_inicio_vigencia.min'      => 'El año de inicio debe ser 2000 o posterior.',
            'anio_fin_vigencia.gte'         => 'El año de fin debe ser igual o mayor al año de inicio.',
            'codigo_pensum.unique'          => 'Ya existe un pensum con ese código.',
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            // Solo validar si los campos base pasaron sin error
            if ($v->errors()->hasAny(['id_carrera', 'anio_inicio_vigencia'])) {
                return;
            }

            $idCarrera  = (int) $this->input('id_carrera');
            $inicioNuevo = (int) $this->input('anio_inicio_vigencia');
            $finNuevo    = $this->input('anio_fin_vigencia') !== null
                ? (int) $this->input('anio_fin_vigencia')
                : 9999;

            // Buscar pensums activos de la misma carrera cuya vigencia se solape
            $solapado = \Illuminate\Support\Facades\DB::table('pensum')
                ->where('id_carrera', $idCarrera)
                ->where('estado', 'activo')
                ->where(function ($q) use ($inicioNuevo, $finNuevo) {
                    // inicioExistente <= finNuevo AND finExistente >= inicioNuevo
                    $q->where('anio_inicio_vigencia', '<=', $finNuevo)
                      ->where(function ($q2) use ($inicioNuevo) {
                          $q2->whereNull('anio_fin_vigencia')                     // fin = 9999
                             ->orWhere('anio_fin_vigencia', '>=', $inicioNuevo);
                      });
                })
                ->exists();

            if ($solapado) {
                $v->errors()->add(
                    'anio_inicio_vigencia',
                    'Ya existe un pensum activo para esta carrera cuya vigencia se cruza con el rango indicado.'
                );
            }
        });
    }
}
