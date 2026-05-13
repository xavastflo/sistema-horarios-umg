<?php

/*
|--------------------------------------------------------------------------
| Configuración del Sistema Académico de Horarios
|--------------------------------------------------------------------------
|
| Constantes configurables sin necesidad de modificar código.
| Para cambiar un valor: modificar .env y ejecutar php artisan config:clear
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Límite de cursos por docente por período académico
    |----------------------------------------------------------------------
    |
    | Regla pendiente de confirmación por la coordinación.
    | El algoritmo de generación de horarios respetará este límite.
    | Valores sugeridos: 6 u 8. Por defecto: 6.
    |
    */
    'max_cursos_docente' => (int) env('MAX_CURSOS_DOCENTE', 6),

    /*
    |----------------------------------------------------------------------
    | Límite de cursos del mismo ciclo por docente
    |----------------------------------------------------------------------
    |
    | Un docente NO puede impartir más de un curso del mismo ciclo/semestre
    | en el mismo período académico.
    | Este valor es 1 y NO debe cambiarse (regla institucional confirmada).
    |
    */
    'max_cursos_mismo_ciclo_docente' => 1,

    /*
    |----------------------------------------------------------------------
    | Duración mínima y máxima de bloques horarios (en minutos)
    |----------------------------------------------------------------------
    */
    'bloque_duracion_minima' => (int) env('BLOQUE_DURACION_MIN', 50),
    'bloque_duracion_maxima' => (int) env('BLOQUE_DURACION_MAX', 180),

    /*
    |----------------------------------------------------------------------
    | Nombre del sistema (para notificaciones y reportes)
    |----------------------------------------------------------------------
    */
    'nombre_sistema' => env('NOMBRE_SISTEMA', 'Sistema de Horarios Universitarios'),

];
