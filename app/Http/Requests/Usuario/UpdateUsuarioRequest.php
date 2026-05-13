<?php

namespace App\Http\Requests\Usuario;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUsuarioRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $idUsuario = $this->route('usuario');

        return [
            'nombres'              => ['sometimes', 'required', 'string', 'max:100'],
            'apellidos'            => ['sometimes', 'required', 'string', 'max:100'],
            // SQL oficial: varchar(50)
            'nombre_usuario'       => [
                'sometimes', 'required', 'string', 'max:50',
                "unique:usuario,nombre_usuario,{$idUsuario},id_usuario",
                'regex:/^[a-zA-Z0-9._-]+$/',
            ],
            // SQL oficial: varchar(120)
            'correo_electronico'   => [
                'sometimes', 'required', 'email', 'max:120',
                "unique:usuario,correo_electronico,{$idUsuario},id_usuario",
            ],
            'telefono'              => ['nullable', 'string', 'max:20'],
            // SQL oficial: ENUM('activo','inactivo','bloqueado')
            'estado'               => ['sometimes', 'required', 'in:activo,inactivo,bloqueado'],
            // En update son opcionales, pero si se envían deben ser válidos
            'pregunta_seguridad'   => ['sometimes', 'required', 'string', 'max:150'],
            'respuesta_seguridad'  => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.in' => 'El estado debe ser activo, inactivo o bloqueado.',
        ];
    }
}
