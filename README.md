Backend — Sistema de Horarios UMGAPI REST robusta construida con Laravel 11. Administra de forma centralizada la lógica de negocio, restricciones de integridad, asignación de docentes y generación de reportes del sistema.Stack TecnológicoHerramientaVersiónUsoPHP8.3+ / 8.5Entorno de ejecución del servidorLaravel11.xFramework backend MVC / API RESTMySQL / MariaDB10.4+Base de datos relacionalLaravel Sanctum3.xAutenticación basada en Tokens de APIDomPDF3.xRenderizado y generación de reportes en PDFLaravel Excel3.xExportación de datos de horarios a hojas de cálculoRequerimientos PreviosAsegúrate de tener instalado en tu Mac/PC:PHP (mínimo 8.3)ComposerMySQL Server (vía XAMPP, MAMP o Docker)Instalación y Configuración LocalClonar el proyecto e ingresar a la carpeta:Bashcd backend
Instalar las dependencias de Composer:(Si trabajas con versiones de vanguardia como PHP 8.5 en desarrollo local, añade el flag de plataforma)Bashcomposer install --ignore-platform-req=php
Crear el archivo de entorno de configuración:Bashcp .env.example .env
Configurar la Base de Datos en el .env:Abre tu .env y configura los accesos correspondientes a tu gestor local (XAMPP/phpMyAdmin):Fragmento de códigoDB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sistema_horarios_umg
DB_USERNAME=root
DB_PASSWORD=
Generar la clave de la aplicación:Bashphp artisan key:generate
Ejecutar Migraciones y Seeders (Base de datos limpia con datos de prueba):Bashphp artisan migrate:fresh --seed
Ejecución del ServidorLevanta el servidor de desarrollo local mediante Artisan:Bashphp artisan serve
El backend quedará accesible por defecto en: http://127.0.0.1:8000Arquitectura de Validación y Control de Flujo1. Intercepción en FormRequestsPara liberar al frontend de cálculos redundantes, se utiliza el ciclo prepareForValidation() para procesar la data entrante antes de que apliquen las reglas de validación (rules()):Módulo de Períodos Académicos (StorePeriodoAcademicoRequest.php): El usuario ingresa un nombre_base (ej: "Primer Semestre") y una fecha_inicio. El backend extrae el año automáticamente y concatena internamente el string definitivo nombre_periodo.2. Manejo de Integridad Atómica con updateOrCreatePara resolver conflictos de restricciones UNIQUE(id_seccion) causados por la baja lógica de docentes (estado = 'inactivo'), el SeccionController.php implementa una estrategia atómica en lugar de inserciones directas:PHP$asignacion = AsignacionDocenteCurso::updateOrCreate(
    ['id_seccion' => $idSeccion],
    [
        'id_docente'          => $request->id_docente,
        'estado'              => 'activo',
        'fecha_asignacion'    => now(),
        'fecha_actualizacion' => now(),
    ]
);
Esto preserva el identificador único (id_asignacion_docente_curso) para no dejar registros huérfanos en la tabla transaccional de historial_cambios.Estructura Esencial del CódigoPlaintextapp/
├── Http/
│   ├── Controllers/
│   │   ├── Api/
│   │   │   ├── AuthController.php       -> Login, logout y switch de roles
│   │   │   ├── SeccionController.php    -> Gestión de secciones y asignación atómica
│   │   │   ├── ReporteController.php    -> Orquestación de Axios Binario para PDF/Excel
│   │   │   └── NotificacionController.php -> Endpoints de alertas globales por usuario
│   ├── Requests/
│   │   └── PeriodoAcademico/
│   │       ├── StorePeriodoAcademicoRequest.php  -> Mutación previa del año
│   │       └── UpdatePeriodoAcademicoRequest.php -> Recálculos dinámicos en edición
├── Models/
│   ├── PeriodoAcademico.php
│   ├── AsignacionDocenteCurso.php
│   └── Notificacion.php
├── Services/
│   └── HistorialService.php             -> Auditoría de cambios de estados de horarios
database/
├── migrations/                           -> Esquemas y restricciones de índices UNIQUE
└── seeders/                              -> Población inicial de usuarios, roles y permisos
routes/
└── api.php                               -> Declaración de rutas bajo prefijo /api
Endpoints Principales de la API (Sprint 4)Autenticación (Sanctum)POST /api/auth/login - Inicio de sesiónPOST /api/auth/logout - Cierre de sesión (revoca token actual)GET /api/auth/me - Retorna el perfil completo del usuario logueadoMódulo de NotificacionesGET /api/notificaciones - Listado total de alertas del usuarioGET /api/notificaciones/no-leidas - Conteo de alertas pendientesPATCH /api/notificaciones/leer-todas - Marcado masivo optimistaPATCH /api/notificaciones/{id}/leer - Cambia el estado a leídoDELETE /api/notificaciones/{id} - Remoción física de la alertaMódulo de Reportes (Axios Binary Response)GET /api/reportes/horario-carrera - Exportación de mallas horarias por carreraGET /api/reportes/horario-docente - Agenda de bloques y salones asignados al docenteGET /api/reportes/secciones-no-asignadas - Alertas de control de aulas vacíasDatos de Acceso para Pruebas (Seeders)Usuario: adminContraseña: Admin@2024!Roles incluidos en el Seeder: administrador, coordinador, docente, estudiante.
