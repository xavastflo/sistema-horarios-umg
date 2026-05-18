# Backend — Sistema de Horarios UMG

API REST robusta construida con Laravel 11. Administra de forma centralizada la lógica de negocio, restricciones de integridad, asignación de docentes y generación de reportes del sistema.

## Stack Tecnológico

| Herramienta | Versión | Uso |
| :--- | :--- | :--- |
| **PHP** | 8.3+ / 8.5 | Entorno de ejecución del servidor |
| **Laravel** | 11.x | Framework backend MVC / API REST |
| **MySQL / MariaDB** | 10.4+ | Base de datos relacional |
| **Laravel Sanctum** | 3.x | Autenticación basada en Tokens de API |
| **DomPDF** | 3.x | Renderizado y generación de reportes en PDF |
| **Laravel Excel** | 3.x | Exportación de datos de horarios a hojas de cálculo |

---

## Requerimientos Previos

Asegúrate de tener instalado en tu Mac/PC:
* PHP (mínimo 8.3)
* Composer
* MySQL Server (vía XAMPP, MAMP o Docker)

---

## Instalación y Configuración Local

1. **Clonar el proyecto e ingresar a la carpeta:**
   ```bash
   cd backend
Instalar las dependencias de Composer:

Bash
composer install --ignore-platform-req=php
Crear el archivo de entorno de configuración:

Bash
cp .env.example .env
Configurar la Base de Datos en el .env:

Fragmento de código
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sistema_horarios_umg
DB_USERNAME=root
DB_PASSWORD=
Generar la clave de la aplicación:

Bash
php artisan key:generate
Ejecutar Migraciones y Seeders:

Bash
php artisan migrate:fresh --seed
Ejecución del Servidor
Levanta el servidor de desarrollo local mediante Artisan:

Bash
php artisan serve
Arquitectura de Validación y Control de Flujo
1. Intercepción en FormRequests
Para liberar al frontend de cálculos redundantes, se utiliza el ciclo prepareForValidation() para procesar la data entrante antes de que apliquen las reglas de validación (rules()):

Módulo de Períodos Académicos (StorePeriodoAcademicoRequest.php): El usuario ingresa un nombre_base (ej: "Primer Semestre") y una fecha_inicio. El backend extrae el año automáticamente y concatena internamente el string definitivo nombre_periodo.

2. Manejo de Integridad Atómica con updateOrCreate
Para resolver conflictos de restricciones UNIQUE(id_seccion) causados por la baja lógica de docentes (estado = 'inactivo'), el SeccionController.php implementa una estrategia atómica en lugar de inserciones directas:

PHP
$asignacion = AsignacionDocenteCurso::updateOrCreate(
    ['id_seccion' => $idSeccion],
    [
        'id_docente'          => $request->id_docente,
        'estado'              => 'activo',
        'fecha_asignacion'    => now(),
        'fecha_actualizacion' => now(),
    ]
);
Esto preserva el identificador único (id_asignacion_docente_curso) para no dejar registros huérfanos en la tabla transaccional de historial_cambios.

Estructura Esencial del Código
Plaintext
app/
├── Http/
│   ├── Controllers/
│   │   ├── Api/
│   │   │   ├── AuthController.php       -> Login, logout y switch de roles
│   │   │   ├── SeccionController.php    -> Gestión de secciones y asignación atómica
│   │   │   └── ReporteController.php    -> Orquestación de Axios Binario para PDF/Excel
│   ├── Requests/
│   │   └── PeriodoAcademico/
│   │       ├── StorePeriodoAcademicoRequest.php
│   │       └── UpdatePeriodoAcademicoRequest.php
├── Models/
│   ├── PeriodoAcademico.php
│   └── AsignacionDocenteCurso.php
└── Services/
    └── HistorialService.php             -> Auditoría de cambios de estados de horarios
Endpoints Principales de la API (Sprint 4)
Autenticación (Sanctum)
POST /api/auth/login - Inicio de sesión

POST /api/auth/logout - Cierre de sesión (revoca token actual)

GET /api/auth/me - Retorna el perfil completo del usuario logueado

Módulo de Notificaciones
GET /api/notificaciones - Listado total de alertas del usuario

GET /api/notificaciones/no-leidas - Conteo de alertas pendientes

PATCH /api/notificaciones/leer-todas - Marcado masivo optimista

Módulo de Reportes
GET /api/reportes/horario-carrera - Exportación de mallas horarias por carrera

GET /api/reportes/horario-docente - Agenda de bloques y salones asignados al docente

Datos de Acceso para Pruebas (Seeders)
Usuario: admin

Contraseña: Admin@2024!

Roles incluidos en el Seeder: administrador, coordinador, docente, estudiante.
