<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Curso;
use App\Models\Pensum;
use App\Models\PensumCurso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * PensumImportController
 *
 * GET  /api/pensums/plantilla-csv               → descarga plantilla CSV de 3 columnas
 * POST /api/pensums/{pensum}/import-csv         → importa cursos masivamente
 *
 * Formato CSV — 3 columnas, primera fila = encabezado (ignorada):
 *   codigo_curso | nombre_curso | ciclo_semestre
 *
 * Estrategia todo o nada:
 *   1. Leer y parsear el CSV completo.
 *   2. Validar TODAS las filas. Si hay errores → 422 sin persistir nada.
 *   3. Persistir dentro de DB::beginTransaction(). Si lanza excepción → rollback.
 *
 * Upsert de cursos:
 *   - Si codigo_curso ya existe en BD → reutiliza el id_curso (no duplica).
 *   - Si no existe → crea el curso con estado 'activo'.
 *
 * Vinculación al pensum:
 *   - Si el curso ya está en pensum_curso con estado activo → omite (no duplica).
 *   - Si no está → inserta en pensum_curso.
 */
class PensumImportController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/pensums/plantilla-csv
    // ─────────────────────────────────────────────────────────────────────────

    public function descargarPlantilla(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filas = [
            // Encabezado — orden estricto
            ['codigo_curso', 'nombre_curso', 'ciclo_semestre'],
            // Filas de ejemplo reales UMG (Ingeniería en Sistemas)
            ['090001', 'Desarrollo Humano',                '1'],
            ['090002', 'Matemática I',                     '1'],
            ['090003', 'Lógica y Algoritmos',              '1'],
            ['090004', 'Fundamentos de Programación',      '1'],
            ['090005', 'Matemática II',                    '2'],
            ['090006', 'Programación Orientada a Objetos', '2'],
            ['090007', 'Estructura de Datos',              '3'],
            ['090008', 'Base de Datos I',                  '3'],
        ];

        return response()->streamDownload(function () use ($filas) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8: evita caracteres corruptos al abrir con Excel en Windows
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($filas as $fila) {
                fputcsv($out, $fila, ';'); // punto y coma: compatible con Excel en español
            }
            fclose($out);
        }, 'plantilla_pensum.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/pensums/{pensum}/import-csv
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param Request $request
     * @param Pensum  $pensum   Route Model Binding: Laravel resuelve automáticamente
     *                          por id_pensum desde la URL.
     */
    public function importarCSV(Request $request, Pensum $pensum): JsonResponse
    {
        // ── 1. Validar que llegó el archivo ───────────────────
        $request->validate([
            'archivo' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ], [
            'archivo.required' => 'Debes adjuntar un archivo CSV.',
            'archivo.mimes'    => 'El archivo debe ser un CSV (.csv).',
            'archivo.max'      => 'El archivo no puede superar 2 MB.',
        ]);

        // ── 2. Leer y parsear el CSV ──────────────────────────
        $handle = fopen($request->file('archivo')->getRealPath(), 'r');

        if ($handle === false) {
            return response()->json([
                'message' => 'No se pudo abrir el archivo. Inténtalo de nuevo.',
            ], 422);
        }

        $filas      = [];
        $numFila    = 0;
        $encabezado = null;

        while (($columnas = fgetcsv($handle, 1000, ';')) !== false) { // punto y coma
            $numFila++;

            // Limpiar BOM, espacios y caracteres de control por columna
            $columnas = array_map(
                fn($v) => trim($v, " \t\r\n\0\x0B\xEF\xBB\xBF"),
                $columnas
            );

            // Ignorar filas completamente vacías
            if (implode('', $columnas) === '') {
                continue;
            }

            // Primera fila no vacía = encabezado → ignorar
            if ($encabezado === null) {
                $encabezado = $columnas;
                continue;
            }

            // Rechazar filas con menos de 3 columnas antes de la validación
            if (count($columnas) < 3) {
                fclose($handle);
                return response()->json([
                    'message' => "El CSV contiene errores de validación. Operación abortada.",
                    'errores' => [
                        "Fila {$numFila}: tiene menos de 3 columnas. "
                        . "Formato requerido: codigo_curso, nombre_curso, ciclo_semestre.",
                    ],
                ], 422);
            }

            $filas[] = [
                'fila'           => $numFila,
                'codigo_curso'   => $columnas[0],
                'nombre_curso'   => $columnas[1],
                'ciclo_semestre' => $columnas[2],
            ];
        }

        fclose($handle);

        if (empty($filas)) {
            return response()->json([
                'message' => 'El CSV no contiene filas de datos. '
                           . 'Asegúrate de incluir datos debajo del encabezado.',
            ], 422);
        }

        // ── 3. Validar TODAS las filas antes de persistir ─────
        //      Si hay un solo error → devolver 422 sin tocar la BD.
        $errores = [];

        foreach ($filas as $datos) {
            $v = Validator::make($datos, [
                'codigo_curso'   => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
                'nombre_curso'   => ['required', 'string', 'max:150'],
                'ciclo_semestre' => ['required', 'integer', 'min:1', 'max:12'],
            ], [
                'codigo_curso.required'   => "Fila {$datos['fila']}: El código de curso es obligatorio.",
                'codigo_curso.max'        => "Fila {$datos['fila']}: El código no puede superar 20 caracteres.",
                'codigo_curso.regex'      => "Fila {$datos['fila']}: El código solo puede contener letras, números, guiones y guiones bajos.",
                'nombre_curso.required'   => "Fila {$datos['fila']}: El nombre del curso es obligatorio.",
                'nombre_curso.max'        => "Fila {$datos['fila']}: El nombre no puede superar 150 caracteres.",
                'ciclo_semestre.required' => "Fila {$datos['fila']}: El ciclo_semestre es obligatorio.",
                'ciclo_semestre.integer'  => "Fila {$datos['fila']}: El ciclo_semestre debe ser un número entero.",
                'ciclo_semestre.min'      => "Fila {$datos['fila']}: El ciclo_semestre debe ser un número entre 1 y 12.",
                'ciclo_semestre.max'      => "Fila {$datos['fila']}: El ciclo_semestre debe ser un número entre 1 y 12.",
            ]);

            foreach ($v->errors()->all() as $mensaje) {
                $errores[] = $mensaje;
            }
        }

        if (!empty($errores)) {
            return response()->json([
                'message' => 'El CSV contiene errores de validación. Operación abortada.',
                'errores' => $errores,
            ], 422);
        }

        // ── 4. Persistir — todo o nada ────────────────────────
        //      DB::beginTransaction() + rollback si lanza excepción.
        DB::beginTransaction();

        try {
            $insertados   = 0; // cursos nuevos creados en tabla 'curso'
            $reutilizados = 0; // cursos que ya existían en tabla 'curso'
            $omitidos     = 0; // cursos que ya estaban en este pensum

            // Pre-cargar IDs ya vinculados al pensum para evitar N consultas en el loop
            $idsCursosPensum = PensumCurso::where('id_pensum', $pensum->id_pensum)
                ->where('estado', 'activo')
                ->pluck('id_curso')
                ->flip(); // convertir a mapa { id_curso => posición } para lookup O(1)

            foreach ($filas as $datos) {
                // Upsert de curso: si existe por codigo_curso lo reutiliza,
                // si no existe lo crea.
                $curso = Curso::firstOrCreate(
                    [
                        'codigo_curso' => strtoupper(trim($datos['codigo_curso'])),
                    ],
                    [
                        'nombre_curso' => trim($datos['nombre_curso']),
                        'estado'       => 'activo',
                    ]
                );

                if ($curso->wasRecentlyCreated) {
                    $insertados++;
                } else {
                    $reutilizados++;
                }

                // Si el curso ya está en este pensum → omitir sin error
                if ($idsCursosPensum->has($curso->id_curso)) {
                    $omitidos++;
                    continue;
                }

                // Vincular curso al pensum
                PensumCurso::create([
                    'id_pensum'      => $pensum->id_pensum,
                    'id_curso'       => $curso->id_curso,
                    'ciclo_semestre' => (int) $datos['ciclo_semestre'],
                    'estado'         => 'activo',
                    'fecha_creacion' => now(),
                ]);

                // Añadir al mapa local para que filas duplicadas en el mismo CSV
                // no intenten insertarlo dos veces
                $idsCursosPensum->put($curso->id_curso, true);
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error interno al guardar los datos. Operación revertida.',
                'detalle' => $e->getMessage(),
            ], 500);
        }

        // ── 5. Respuesta exitosa ──────────────────────────────
        $total = count($filas);

        return response()->json([
            'message' => 'Importación completada.',
            'resumen' => [
                'filas_procesadas'       => $total,
                'cursos_añadidos_pensum' => $total - $omitidos,
                'cursos_nuevos_en_bd'    => $insertados,
                'cursos_existentes_bd'   => $reutilizados,
                'cursos_omitidos'        => $omitidos,
            ],
        ]);
    }
}
