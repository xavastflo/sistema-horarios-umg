<?php

namespace App\Http\Requests\Usuario;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUsuarioRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nombres'              => ['required', 'string', 'max:100'],
            'apellidos'            => ['required', 'string', 'max:100'],
            // SQL oficial: varchar(50)
            'nombre_usuario'       => ['required', 'string', 'max:50', 'unique:usuario,nombre_usuario', 'regex:/^[a-zA-Z0-9._-]+$/'],
            // SQL oficial: varchar(120)
            'correo_electronico'   => ['required', 'email', 'max:120', 'unique:usuario,correo_electronico'],
            'telefono'             => ['nullable', 'string', 'max:20'],
            'password'             => ['required', Password::min(8)->mixedCase()->numbers(), 'confirmed'],
            // SQL oficial: NOT NULL — obligatorios siempre
            'pregunta_seguridad'   => ['required', 'string', 'max:150'],
            'respuesta_seguridad'  => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre_usuario.unique'         => 'El nombre de usuario ya está en uso.',
            'nombre_usuario.regex'          => 'Solo puede contener letras, números, puntos, guiones y guiones bajos.',
            'correo_electronico.unique'     => 'El correo electrónico ya está registrado.',
            'password.confirmed'            => 'La confirmación de contraseña no coincide.',
            'pregunta_seguridad.required'   => 'La pregunta de seguridad es obligatoria.',
            'respuesta_seguridad.required'  => 'La respuesta de seguridad es obligatoria.',
        ];
    }
}
