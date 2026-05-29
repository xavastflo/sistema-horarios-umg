<?php

namespace App\Http\Requests\Pensum;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class UpdatePensumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $pensumRuta = $this->route('pensum');
        $id = is_object($pensumRuta) ? $pensumRuta->id_pensum : $pensumRuta;

        return [
            'nombre_pensum'        => ['sometimes', 'required', 'string', 'max:120'],
            'codigo_pensum'        => ['sometimes', 'required', 'string', 'max:20', "unique:pensum,codigo_pensum,{$id},id_pensum"],
            'descripcion'          => ['nullable', 'string', 'max:200'],
            'estado'               => ['sometimes', 'required', 'in:activo,inactivo'],
            'anio_inicio_vigencia' => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'anio_fin_vigencia'    => ['nullable', 'integer', 'min:2000', 'max:2100', 'gte:anio_inicio_vigencia'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->any()) {
                return;
            }

            $pensumRuta = $this->route('pensum');
            $idPensum = is_object($pensumRuta) ? $pensumRuta->id_pensum : $pensumRuta;

            $pensumActual = DB::table('pensum')
                ->where('id_pensum', $idPensum)
                ->first();

            if (! $pensumActual) {
                return;
            }

            $cambiaVigencia = $this->has('anio_inicio_vigencia') || $this->has('anio_fin_vigencia');

            $estadoOriginal = $pensumActual->estado;
            $estadoFinal    = $this->input('estado', $estadoOriginal);

            $seEstaActivando = $estadoOriginal !== 'activo' && $estadoFinal === 'activo';

            $requiereValidarSolapamiento = $estadoFinal === 'activo' && ($cambiaVigencia || $seEstaActivando);

            if (! $requiereValidarSolapamiento) {
                return;
            }

            $idCarrera = $pensumActual->id_carrera;

            $inicioNuevo = (int) $this->input(
                'anio_inicio_vigencia',
                $pensumActual->anio_inicio_vigencia
            );

            $finNuevoInput = $this->input(
                'anio_fin_vigencia',
                $pensumActual->anio_fin_vigencia
            );

            $finNuevo = $finNuevoInput ? (int) $finNuevoInput : 9999;

            if ($finNuevo < $inicioNuevo) {
                $validator->errors()->add(
                    'anio_fin_vigencia',
                    'El año de fin de vigencia debe ser mayor o igual al año de inicio.'
                );
                return;
            }

            $solapado = DB::table('pensum')
                ->where('id_carrera', $idCarrera)
                ->where('estado', 'activo')
                ->where('id_pensum', '!=', $idPensum)
                ->where(function ($query) use ($inicioNuevo, $finNuevo) {
                    $query->where('anio_inicio_vigencia', '<=', $finNuevo)
                        ->where(function ($q) use ($inicioNuevo) {
                            $q->whereNull('anio_fin_vigencia')
                                ->orWhere('anio_fin_vigencia', '>=', $inicioNuevo);
                        });
                })
                ->exists();

            if ($solapado) {
                $campoError = $this->has('anio_fin_vigencia')
                    ? 'anio_fin_vigencia'
                    : 'anio_inicio_vigencia';

                $validator->errors()->add(
                    $campoError,
                    'Ya existe un pensum activo para esta carrera cuya vigencia se cruza con el rango indicado.'
                );
            }
        });
    }
}
