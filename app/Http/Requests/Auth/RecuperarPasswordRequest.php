<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RecuperarPasswordRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nombre_usuario'   => ['required', 'string', 'max:60'],
            'respuesta'        => ['required', 'string', 'max:255'],
            'nueva_password'   => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre_usuario.required' => 'El nombre de usuario es obligatorio.',
            'respuesta.required'      => 'La respuesta de seguridad es obligatoria.',
            'nueva_password.required' => 'La nueva contraseña es obligatoria.',
            'nueva_password.min'      => 'La contraseña debe tener al menos 8 caracteres.',
            'nueva_password.confirmed'=> 'La confirmación de contraseña no coincide.',
        ];
    }
}
