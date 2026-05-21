<?php

use App\Http\Controllers\Api\AsignacionDocenteCursoController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BloqueHorarioController;
use App\Http\Controllers\Api\CarreraController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\CentroEducativoController;
use App\Http\Controllers\Api\CursoController;
use App\Http\Controllers\Api\DisponibilidadDocenteController;
use App\Http\Controllers\Api\DocenteController;
use App\Http\Controllers\Api\FacultadController;
use App\Http\Controllers\Api\HistorialController;
use App\Http\Controllers\Api\GeneracionHorarioController;
use App\Http\Controllers\Api\HorarioController;
use App\Http\Controllers\Api\NotificacionController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\PensumController;
use App\Http\Controllers\Api\PeriodoAcademicoController;
use App\Http\Controllers\Api\SeccionController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\PensumImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Sistema de Horarios Universitarios
| Sprint 1 + Sprint 2
|--------------------------------------------------------------------------
|
| REGLA DE ORDEN: Las rutas literales (sin parámetro) SIEMPRE antes
| que las rutas con parámetro dinámico {id} dentro del mismo grupo.
|
| Rutas fijas corregidas:
|   - GET  perfil/docente  (fuera del namespace docentes/*)
|   - POST bloques-horario/generar → antes de bloques-horario/{bloque}
|   - GET  asignaciones/docente/.../periodo/... → antes de asignaciones/{id}
|
*/

// ═══════════════════════════════════════════════════════════════════
// RUTAS PÚBLICAS — sin autenticación
// ═══════════════════════════════════════════════════════════════════

Route::prefix('auth')->group(function () {
    Route::post('login',              [AuthController::class, 'login']);
    Route::post('recuperar-password', [AuthController::class, 'recuperarPassword']);
    Route::get('pregunta-seguridad/{nombre_usuario}', [AuthController::class, 'preguntaSeguridad']);
});

Route::prefix('catalogos')->group(function () {
    Route::get('roles',           [CatalogoController::class, 'roles']);
    Route::get('jornadas',        [CatalogoController::class, 'jornadas']);
    Route::get('dias',            [CatalogoController::class, 'dias']);
    Route::get('estados-horario', [CatalogoController::class, 'estadosHorario']);
});

// ═══════════════════════════════════════════════════════════════════
// RUTAS PROTEGIDAS — requieren token Sanctum
// ═══════════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->group(function () {

    // Sesión y perfil
    Route::get('auth/me',              [AuthController::class, 'me']);
    Route::post('auth/logout',         [AuthController::class, 'logout']);
    Route::post('auth/cambiar-perfil', [AuthController::class, 'cambiarPerfil']);

    // ─────────────────────────────────────────────────────────────
    // ROL: administrador
    // ─────────────────────────────────────────────────────────────
    Route::middleware('rol:administrador')->group(function () {

        // Usuarios
        Route::get('usuarios',                          [UsuarioController::class, 'index']);
        Route::post('usuarios',                         [UsuarioController::class, 'store']);
        Route::get('usuarios/{usuario}',                [UsuarioController::class, 'show']);
        Route::put('usuarios/{usuario}',                [UsuarioController::class, 'update']);
        Route::delete('usuarios/{usuario}',             [UsuarioController::class, 'destroy']);
        Route::post('usuarios/{usuario}/roles',         [UsuarioController::class, 'asignarRol']);
        Route::delete('usuarios/{usuario}/roles/{rol}', [UsuarioController::class, 'quitarRol']);

        // Centros Educativos (Sedes)
        Route::get('centros-educativos',              [CentroEducativoController::class, 'index']);
        Route::post('centros-educativos',             [CentroEducativoController::class, 'store']);
        Route::get('centros-educativos/{id}',         [CentroEducativoController::class, 'show']);
        Route::put('centros-educativos/{id}',         [CentroEducativoController::class, 'update']);
        Route::delete('centros-educativos/{id}',      [CentroEducativoController::class, 'destroy']);

        // Facultades
        Route::get('facultades',               [FacultadController::class, 'index']);
        Route::post('facultades',              [FacultadController::class, 'store']);
        Route::get('facultades/{facultad}',    [FacultadController::class, 'show']);
        Route::put('facultades/{facultad}',    [FacultadController::class, 'update']);
        Route::delete('facultades/{facultad}', [FacultadController::class, 'destroy']);

        // Carreras — escritura solo admin
        Route::post('carreras',                         [CarreraController::class, 'store']);
        Route::put('carreras/{carrera}',                [CarreraController::class, 'update']);
        Route::delete('carreras/{carrera}',             [CarreraController::class, 'destroy']);
        Route::post('carreras/{carrera}/coordinador',   [CarreraController::class, 'asignarCoordinador']);
        Route::delete('carreras/{carrera}/coordinador', [CarreraController::class, 'desasignarCoordinador']);

        // Horarios — transiciones administrativas (Paso 6)
        Route::patch('horarios/{horario}/aprobar',   [HorarioController::class, 'aprobar']);
        Route::patch('horarios/{horario}/bloquear',  [HorarioController::class, 'bloquear']);
        Route::patch('horarios/{horario}/publicar',  [HorarioController::class, 'publicar']);

        // Historial
        Route::get('historial',              [HistorialController::class, 'index']);
        Route::get('historial/{tabla}/{id}', [HistorialController::class, 'porRegistro']);
    });

    // ─────────────────────────────────────────────────────────────
    // ROL: administrador + coordinador
    // ─────────────────────────────────────────────────────────────
    Route::middleware('rol:administrador,coordinador')->group(function () {

        // Carreras — lectura y jornadas
        Route::get('carreras',                    [CarreraController::class, 'index']);
        Route::get('carreras/{carrera}',          [CarreraController::class, 'show']);
        Route::post('carreras/{carrera}/jornadas', [CarreraController::class, 'asignarJornadas']);

        // Docentes (Rutas literales antes que dinámicas)
        Route::get('docentes',                       [DocenteController::class, 'index']);
        Route::post('docentes',                      [DocenteController::class, 'store']);
        Route::get('docentes/{docente}',             [DocenteController::class, 'show']);
        Route::put('docentes/{docente}',             [DocenteController::class, 'update']);
        Route::delete('docentes/{docente}',          [DocenteController::class, 'destroy']);
        Route::patch('docentes/{docente}/prioridad', [DocenteController::class, 'actualizarPrioridad']);

        // Disponibilidad — consulta por admin/coord
        Route::get('docentes/{docente}/disponibilidad', [DisponibilidadDocenteController::class, 'index']);

        // Períodos académicos
        Route::get('periodos-academicos',                            [PeriodoAcademicoController::class, 'index']);
        Route::post('periodos-academicos',                           [PeriodoAcademicoController::class, 'store']);
        Route::get('periodos-academicos/{periodo}',                  [PeriodoAcademicoController::class, 'show']);
        Route::put('periodos-academicos/{periodo}',                  [PeriodoAcademicoController::class, 'update']);
        Route::delete('periodos-academicos/{periodo}',               [PeriodoAcademicoController::class, 'destroy']);
        Route::patch('periodos-academicos/{periodo}/marcar-vigente', [PeriodoAcademicoController::class, 'marcarVigente']);

        // Cursos
        Route::get('cursos',            [CursoController::class, 'index']);
        Route::post('cursos',           [CursoController::class, 'store']);
        Route::get('cursos/{curso}',    [CursoController::class, 'show']);
        Route::put('cursos/{curso}',    [CursoController::class, 'update']);
        Route::delete('cursos/{curso}', [CursoController::class, 'destroy']);

        // ── Rutas de Carga Masiva de Pensums (¡Evita la colisión de parámetros!) ──
        Route::get('pensums/plantilla-csv',        [PensumImportController::class, 'descargarPlantilla']);
        Route::post('pensums/{pensum}/import-csv', [PensumImportController::class, 'importarCSV']);

        // Pensums (CRUD clásico)
        Route::get('pensums',                                  [PensumController::class, 'index']);
        Route::post('pensums',                                 [PensumController::class, 'store']);
        Route::get('pensums/{pensum}',                         [PensumController::class, 'show']);
        Route::put('pensums/{pensum}',                         [PensumController::class, 'update']);
        Route::delete('pensums/{pensum}',                      [PensumController::class, 'destroy']);
        Route::get('pensums/{pensum}/cursos',                  [PensumController::class, 'cursos']);
        Route::post('pensums/{pensum}/cursos',                 [PensumController::class, 'agregarCurso']);
        Route::delete('pensums/{pensum}/cursos/{pensumCurso}', [PensumController::class, 'quitarCurso']);
        Route::patch('pensums/{pensum}/cursos/{pensumCurso}',  [PensumController::class, 'actualizarCiclo']);

        // Bloques horarios (Rutas literales antes que dinámicas)
        Route::get('bloques-horario',              [BloqueHorarioController::class, 'index']);
        Route::post('bloques-horario',             [BloqueHorarioController::class, 'store']);
        Route::post('bloques-horario/generar',     [BloqueHorarioController::class, 'generar']);
        Route::get('bloques-horario/{bloque}',     [BloqueHorarioController::class, 'show']);
        Route::delete('bloques-horario/{bloque}',  [BloqueHorarioController::class, 'destroy']);
        Route::get('carrera-jornadas/{carreraJornada}/bloques', [BloqueHorarioController::class, 'porCarreraJornada']);

        // Secciones
        Route::get('secciones',                         [SeccionController::class, 'index']);
        Route::post('secciones',                        [SeccionController::class, 'store']);
        Route::get('secciones/{seccion}',               [SeccionController::class, 'show']);
        Route::delete('secciones/{seccion}',            [SeccionController::class, 'destroy']);
        Route::get('secciones/{seccion}/asignacion',    [SeccionController::class, 'asignacion']);
        Route::post('secciones/{seccion}/asignacion',   [SeccionController::class, 'asignarDocente']);
        Route::delete('secciones/{seccion}/asignacion', [SeccionController::class, 'quitarDocente']);

        // Horarios (Consulta y Generación automática)
        Route::get('horarios',                 [HorarioController::class, 'index']);
        Route::get('horarios/por-carrera',     [HorarioController::class, 'porCarrera']);
        Route::post('horarios/generar',        [GeneracionHorarioController::class, 'generar']);
        Route::get('horarios/{horario}',       [HorarioController::class, 'show']);
        Route::get('horarios/{horario}/detalles', [HorarioController::class, 'detalles']);
        Route::get('horarios/{horario}/transiciones', [HorarioController::class, 'transicionesDisponibles']);
        Route::get('horarios/{horario}/completo', [HorarioController::class, 'completo']);
        Route::patch('horarios/{horario}/detalles/{detalle}/mover', [HorarioController::class, 'moverDetalle']);
        Route::delete('horarios/{horario}/detalles/{detalle}',     [HorarioController::class, 'eliminarDetalle']);

        // Asignaciones (Rutas literales antes que dinámicas)
        Route::get('asignaciones',                                     [AsignacionDocenteCursoController::class, 'index']);
        Route::get('asignaciones/docente/{docente}/periodo/{periodo}', [AsignacionDocenteCursoController::class, 'porDocenteYPeriodo']);
        Route::get('asignaciones/{asignacion}',                        [AsignacionDocenteCursoController::class, 'show']);
    });

    // ─────────────────────────────────────────────────────────────
    // ROL: docente — perfil propio y disponibilidad
    // ─────────────────────────────────────────────────────────────
    Route::middleware('rol:docente')->group(function () {

        Route::get('docente/horario', [HorarioController::class, 'miHorario']);

        Route::get('perfil/docente', function (\Illuminate\Http\Request $request) {
            $docente = $request->user()->docente()->with('usuario')->first();
            if (! $docente) {
                return response()->json(['message' => 'Perfil docente no encontrado.'], 404);
            }
            return response()->json($docente);
        });

        // Disponibilidad
        Route::get('docentes/{docente}/disponibilidad',                     [DisponibilidadDocenteController::class, 'index']);
        Route::post('docentes/{docente}/disponibilidad/toggle',             [DisponibilidadDocenteController::class, 'toggle']);
        Route::post('docentes/{docente}/disponibilidad',                    [DisponibilidadDocenteController::class, 'store']);
        Route::delete('docentes/{docente}/disponibilidad/{disponibilidad}', [DisponibilidadDocenteController::class, 'destroy']);
    });

    // ─────────────────────────────────────────────────────────────
    // Notificaciones — accesibles para cualquier usuario autenticado
    // ─────────────────────────────────────────────────────────────
    Route::get('notificaciones',               [NotificacionController::class, 'index']);
    Route::get('notificaciones/no-leidas',     [NotificacionController::class, 'noLeidas']);
    Route::patch('notificaciones/leer-todas',  [NotificacionController::class, 'leerTodas']);
    Route::patch('notificaciones/{id}/leer',   [NotificacionController::class, 'leer']);
    Route::delete('notificaciones/{id}',       [NotificacionController::class, 'destroy']);

    // ─────────────────────────────────────────────────────────────
    // Reportes PDF/Excel
    // ─────────────────────────────────────────────────────────────
    Route::middleware('rol:administrador,coordinador')->group(function () {
        Route::get('reportes/horario-carrera',        [ReporteController::class, 'horarioCarrera']);
        Route::get('reportes/secciones-no-asignadas', [ReporteController::class, 'seccionesNoAsignadas']);
        Route::get('reportes/resumen-asignaciones',   [ReporteController::class, 'resumenAsignaciones']);
    });

    Route::middleware('rol:administrador,coordinador,docente')->group(function () {
        Route::get('reportes/horario-docente', [ReporteController::class, 'horarioDocente']);
    });

    // ─────────────────────────────────────────────────────────────
    // ROL: estudiante — solo horarios publicados
    // ─────────────────────────────────────────────────────────────
    Route::middleware('rol:estudiante')->group(function () {
        Route::get('estudiante/horario', [HorarioController::class, 'estudianteHorario']);
    });

});
