<?php

namespace App\Http\Requests\Pensum;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePensumRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('pensum');

        return [
            'nombre_pensum' => ['sometimes', 'required', 'string', 'max:120'],
            'codigo_pensum' => ['sometimes', 'required', 'string', 'max:20', "unique:pensum,codigo_pensum,{$id},id_pensum"],
            'descripcion'   => ['nullable', 'string', 'max:200'],
            'estado'        => ['sometimes', 'required', 'in:activo,inactivo'],
        ];
    }
}
