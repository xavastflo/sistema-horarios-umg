<?php

namespace App\Http\Requests\Seccion;

use App\Models\CarreraJornada;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class StoreSeccionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id_carrera_jornada'  => ['required', 'integer', 'exists:carrera_jornada,id_carrera_jornada'],
            'id_curso'            => ['required', 'integer', 'exists:curso,id_curso'],
            'id_periodo_academico'=> ['required', 'integer', 'exists:periodo_academico,id_periodo_academico'],
            'numero_seccion'      => [
                'required', 'string', 'max:10',
                Rule::unique('seccion')->where(function ($q) {
                    $q->where('id_carrera_jornada',  $this->id_carrera_jornada)
                      ->where('id_curso',             $this->id_curso)
                      ->where('id_periodo_academico', $this->id_periodo_academico);
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'id_carrera_jornada.required' => 'Debes seleccionar la carrera y jornada.',
            'id_carrera_jornada.exists'   => 'La carrera-jornada seleccionada no existe.',
            'id_curso.required'           => 'Debes seleccionar un curso.',
            'numero_seccion.unique'       => 'Ya existe esa sección para este curso, período y jornada.',
        ];
    }

    /**
     * Fix 4: validar que el curso pertenezca al pensum de la carrera.
     *
     * Cadena: id_carrera_jornada → carrera_jornada.id_carrera
     *       → pensum.id_carrera → pensum_curso.id_pensum
     *       → pensum_curso.id_curso === $request->id_curso
     *
     * Impide asignar "Bioquímica" (Medicina) a una sección de Sistemas.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Solo ejecutar si las reglas básicas pasaron
            if ($v->errors()->isNotEmpty()) return;

            $idCarreraJornada = (int) $this->input('id_carrera_jornada');
            $idCurso          = (int) $this->input('id_curso');

            // Resolver id_carrera desde la carrera_jornada
            $idCarrera = DB::table('carrera_jornada')
                ->where('id_carrera_jornada', $idCarreraJornada)
                ->value('id_carrera');

            if (! $idCarrera) return; // ya falló en exists, no redundar

            // Verificar que el curso esté en algún pensum de esa carrera
            $existe = DB::table('pensum_curso as pc')
                ->join('pensum as p', 'p.id_pensum', '=', 'pc.id_pensum')
                ->where('p.id_carrera', $idCarrera)
                ->where('pc.id_curso',  $idCurso)
                ->where('pc.estado',    'activo')
                ->where('p.estado',     'activo')
                ->exists();

            if (! $existe) {
                $v->errors()->add(
                    'id_curso',
                    'El curso seleccionado no pertenece al pensum de la carrera asociada a esa jornada.'
                );
            }
        });
    }
}
