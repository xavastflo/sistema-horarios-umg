# Revisión Final de Consolidación Backend
## Sistema de Horarios Universitarios — Cierre Sprint 4

---

## 1. Resumen ejecutivo

El backend del Sistema de Horarios Universitarios está construido sobre **Laravel 11 + PHP 8.2 + MySQL 8.0** y cubre el ciclo de vida completo de la gestión de horarios universitarios: desde la configuración inicial de la institución hasta la publicación del horario y su consulta por todos los roles del sistema.

El proyecto implementa **106 endpoints REST** organizados en 19 módulos, protegidos con Laravel Sanctum y un middleware de roles propio. La lógica de negocio más compleja reside en el algoritmo de generación de horarios (Sprint 3), que opera sin persistir hasta que el coordinador confirma el resultado, garantizando que no queden datos inconsistentes en la base de datos.

Todos los módulos han sido construidos sobre el **SQL oficial** del sistema como fuente de verdad. Ninguna decisión de modelo se tomó sin consultar el DDL. El sistema está listo para que el frontend consuma la API.

---

## 2. Estado por sprint

### Sprint 1 — Base y autenticación ✅
Autenticación con Sanctum, recuperación de contraseña con pregunta de seguridad, gestión de usuarios y roles, facultades, carreras, docentes, historial de cambios, catálogos (roles, jornadas, días, estados de horario), middleware `CheckRol`, seeder con usuario administrador inicial.

### Sprint 2 — Estructura académica ✅
Períodos académicos, cursos, pensums, pensum-cursos con `ciclo_semestre`, bloques horarios con generación automática por rango horario y exclusiones, disponibilidad docente como sistema de bloqueo, secciones, asignación docente-sección con todas las validaciones de negocio (MAX_CURSOS_DOCENTE, no repetir ciclo por período, una sección = un docente).

### Sprint 3 — Algoritmo de horarios ✅
`ConflictValidationService` con 6 validaciones independientes usando traslape real de tiempo. `BloqueCandidatoService` con estrategia de precarga en 4 queries para eficiencia. `GeneradorParcialService` sin persistencia con control en memoria de docente, franja y ciclo. `PersistenciaHorarioService` transaccional con `lockForUpdate()`. `EdicionManualService` para modificación manual con revalidación completa. `HorarioStateService` con máquina de estados explícita (borrador → generado → aprobado → bloqueado/publicado).

### Sprint 4 — Consultas, notificaciones, reportes y documentación ✅
Endpoints de consulta por carrera, docente y estudiante con permisos diferenciados. Rutas `/docente/horario` y `/estudiante/horario` fuera del namespace `horarios/*` para evitar colisiones. Sistema de notificaciones con `NotificacionService` inyectable, integrado post-commit en los 3 servicios de negocio principales. Reportes PDF/Excel en 4 modalidades con `DomPDF` y `Maatwebsite/Excel`. Documentación completa en Markdown.

---

## 3. Módulos implementados

| Módulo | Sprint | Descripción |
|---|---|---|
| Autenticación | 1 | Login, logout, recuperación de contraseña, cambio de perfil activo |
| Usuarios y roles | 1 | CRUD usuarios, asignación/remoción de roles, multi-rol |
| Facultades | 1 | CRUD con soft-delete lógico |
| Carreras | 1 | CRUD, asignación de coordinador, asociación de jornadas |
| Docentes | 1 | CRUD, gestión de prioridad (1=alta, 2=media, 3=baja) |
| Catálogos | 1 | Roles, jornadas, días, estados de horario (públicos) |
| Historial | 1 | Registro de todos los cambios con usuario, tipo y valores |
| Períodos académicos | 2 | CRUD, marcado de vigente, estados propios (planificacion→finalizado) |
| Cursos | 2 | CRUD global de catálogo de cursos |
| Pensums | 2 | CRUD, gestión de cursos por pensum con ciclo_semestre |
| Bloques horarios | 2 | CRUD individual + generación automática con exclusiones |
| Disponibilidad docente | 2 | Bloqueo de franjas por el docente, toggle ON/OFF |
| Secciones | 2 | CRUD, asignación docente con validaciones de negocio |
| Asignaciones | 2 | Consulta y gestión de asignaciones docente-sección |
| Generación de horarios | 3 | Algoritmo sin persistencia, control en memoria |
| Persistencia de horarios | 3 | Confirmación transaccional con revalidación |
| Edición manual | 3 | Mover y eliminar detalles con validación completa |
| Flujo administrativo | 3 | Máquina de estados: aprobar, bloquear, publicar |
| Consultas de horarios | 4 | Por carrera, docente autenticado y estudiante |
| Notificaciones | 4 | Creación automática post-commit, CRUD personal |
| Reportes | 4 | PDF/Excel: horario, docente, secciones no asignadas, resumen |

---

## 4. Servicios principales y responsabilidad

| Servicio | Responsabilidad |
|---|---|
| `HistorialService` | Centraliza el registro de cambios en `historial_cambios`. Todas las operaciones de escritura lo invocan. |
| `BloqueHorarioService` | Generación automática de bloques por rango horario respetando exclusiones. Algoritmo con garantía de no-ciclo-infinito. |
| `ConflictValidationService` | 6 validaciones de conflicto de horario independientes. Usa traslape real (día + hora). Separado de las transiciones administrativas. |
| `BloqueCandidatoService` | Dado un docente-sección-horario, devuelve bloques válidos e inválidos. Estrategia de 4 queries de precarga + lookup O(1). |
| `GeneradorParcialService` | Genera propuestas de asignación en memoria sin persistir. Prioridad docente ASC. Control de conflictos en memoria con traslape real. Registra secciones sin docente. |
| `PersistenciaHorarioService` | Persiste propuestas en `detalle_horario` dentro de una transacción. `lockForUpdate()`, revalidación individual, historial granular. |
| `EdicionManualService` | Mueve o elimina detalles de horario con todas las validaciones. `lockForUpdate()` sobre horario, período y detalle. Verifica que el bloque destino pertenezca a la misma carrera. |
| `HorarioStateService` | Ejecuta transiciones administrativas de estado con máquina de estados explícita. `lockForUpdate()`, verifica existencia de detalles activos antes de aprobar/bloquear/publicar. |
| `HorarioConsultaService` | Queries de consulta de horarios para los distintos roles. Restricción de coordinador por carrera. `ciclo_semestre` resuelto via Pensum correcto. |
| `NotificacionService` | Centraliza la creación de notificaciones. Inyectable. Deduplica destinatarios, filtra nulls, maneja errores individuales con `Log::error()`. |
| `ReporteDataService` | Datos para los 4 reportes. Reutiliza `HorarioConsultaService`. `ciclo_semestre` siempre por `id_curso` + `id_pensum`. Valida coherencia de `id_horario` con carrera/período. |

---

## 5. Controllers principales y responsabilidad

| Controller | Endpoints | Responsabilidad |
|---|---|---|
| `AuthController` | 6 | Login, logout, perfil, cambio de rol, recuperación de contraseña |
| `UsuarioController` | 7 | CRUD usuarios + gestión de roles |
| `FacultadController` | 5 | CRUD facultades |
| `CarreraController` | 8 | CRUD carreras, coordinador, jornadas |
| `DocenteController` | 7 | CRUD docentes, gestión de prioridad |
| `CatalogoController` | 4 | Endpoints públicos de catálogos |
| `HistorialController` | 2 | Consulta de historial (solo admin) |
| `PeriodoAcademicoController` | 6 | CRUD períodos, marcar vigente |
| `CursoController` | 5 | CRUD cursos |
| `PensumController` | 9 | CRUD pensums + cursos del pensum |
| `BloqueHorarioController` | 6 | CRUD bloques + generación automática |
| `DisponibilidadDocenteController` | 4 | Bloqueos del docente + toggle |
| `SeccionController` | 7 | CRUD secciones + asignación docente |
| `AsignacionDocenteCursoController` | 3 | Consulta de asignaciones |
| `HorarioController` | 13 | Consulta, edición manual y transiciones de estado |
| `NotificacionController` | 5 | Gestión de notificaciones propias |
| `ReporteController` | 4 | Generación de reportes PDF/Excel |

---

## 6. Migraciones existentes y propósito

**Total: 23 migraciones** en 4 grupos por prefijo de fecha.

### Sprint 1 — `2024_01_01_*` (12 migraciones)
| Archivo | Tabla | Propósito |
|---|---|---|
| `000001` | `rol` | Catálogo de roles del sistema |
| `000002` | `usuario` | Usuarios con pregunta de seguridad y perfil activo |
| `000003` | `usuario_rol` | Relación usuario-rol (multi-rol) |
| `000004` | `personal_access_tokens` | Tokens de Sanctum |
| `000005` | `facultad` | Unidades académicas de nivel superior |
| `000006` | `jornada` | Catálogo de jornadas (matutina, vespertina, fin_de_semana) |
| `000007` | `carrera` | Carreras con FK a facultad y coordinador |
| `000007b` | `carrera_jornada` | Relación carrera-jornada (tabla pivote) |
| `000008` | `dia` | Días de la semana con orden numérico |
| `000009` | `estado_horario` | Estados del ciclo de vida del horario |
| `000010` | `docente` | Perfil docente con prioridad CHECK IN(1,2,3) |
| `000011` | `historial_cambios` | Auditoría completa de cambios con usuario NOT NULL |

### Sprint 2 — `2024_01_02_*` (8 migraciones)
| Archivo | Tabla | Propósito |
|---|---|---|
| `000001` | `periodo_academico` | Períodos con ENUM 4 estados propios, UNIQUE(anio, numero_periodo) |
| `000002` | `curso` | Catálogo global de cursos |
| `000003` | `pensum` | Pensum por carrera y período |
| `000004` | `pensum_curso` | Asociación pensum-curso con `ciclo_semestre`, UNIQUE(pensum, curso) |
| `000005` | `bloque_horario` | Bloques de tiempo por carrera-jornada y día, UNIQUE(cj, dia, hi, hf) |
| `000006` | `disponibilidad_docente` | Bloqueos de docente, campo `fecha_registro`, UNIQUE(docente, bloque) |
| `000007` | `seccion` | Secciones por curso y período, UNIQUE(curso, periodo, numero) |
| `000008` | `asignacion_docente_curso` | Asignación docente-sección, UNIQUE(id_seccion) = 1 docente por sección |

### Sprint 3 — `2024_01_03_*` (2 migraciones)
| Archivo | Tabla | Propósito |
|---|---|---|
| `000001` | `horario` | Horario con estado, versión y fechas de auditoría, UNIQUE(carrera, periodo, version) |
| `000002` | `detalle_horario` | Detalle de clase: bloque + asignación + día, UNIQUE(horario, bloque) |

### Sprint 4 — `2024_01_04_*` (1 migración)
| Archivo | Tabla | Propósito |
|---|---|---|
| `000001` | `notificacion` | Notificaciones de usuario con tipo ENUM, leída/no leída, FK RESTRICT |

---

## 7. Seeders existentes

| Seeder | Datos sembrados | IDs fijos |
|---|---|---|
| `RolSeeder` | administrador(1), coordinador(2), docente(3), estudiante(4) | Sí |
| `JornadaSeeder` | matutina(1), vespertina(2), fin_de_semana(3) | Sí |
| `DiaSeeder` | lunes(1)–domingo(7), minúsculas sin tilde, orden_semana | Sí |
| `EstadoHorarioSeeder` | borrador(1), generado(2), aprobado(3), bloqueado(4), publicado(5) | Sí |
| `AdminSeeder` | usuario admin: `admin` / `Admin@2024!`, rol administrador | Sí |
| `DatabaseSeeder` | Orquesta en orden: Rol → Jornada → Dia → EstadoHorario → Admin | — |

> Los IDs de roles, jornadas, días y estados son fijos y referenciados en el código. No modificar el orden de inserción de los seeders.

---

## 8. Rutas críticas aprobadas

Las siguientes rutas tienen reglas de orden o semántica especiales que deben preservarse:

| Ruta | Regla |
|---|---|
| `POST /bloques-horario/generar` | Literal antes de `GET /bloques-horario/{bloque}` en el mismo grupo |
| `PATCH /notificaciones/leer-todas` | Literal antes de `PATCH /notificaciones/{id}/leer` |
| `GET /notificaciones/no-leidas` | Literal antes de `GET /notificaciones/{id}` |
| `GET /asignaciones/docente/{d}/periodo/{p}` | Literal antes de `GET /asignaciones/{id}` |
| `GET /docente/horario` | Fuera de `horarios/*` para evitar colisión con `{horario}` |
| `GET /estudiante/horario` | Fuera de `horarios/*`, en grupo `rol:estudiante` separado |
| `GET /horarios/por-carrera` | Literal antes de `GET /horarios/{horario}` en el mismo grupo |
| `GET /perfil/docente` | Fuera de `docentes/*` para evitar colisión con `{docente}` |
| `PATCH /horarios/{id}/detalles/{det}/mover` | Literal `mover` antes de `DELETE /horarios/{id}/detalles/{det}` |

---

## 9. Reglas de negocio implementadas

| Regla | Dónde se implementa |
|---|---|
| Un docente no puede tener más de `MAX_CURSOS_DOCENTE` secciones por período | `SeccionController::asignarDocente()` |
| Un docente no puede tener más de una sección del mismo `ciclo_semestre` por período | `SeccionController::asignarDocente()` |
| Una sección tiene máximo un docente activo | UNIQUE(id_seccion) en BD + validación en controller |
| Un bloque horario no puede aparecer dos veces en el mismo horario | UNIQUE(id_horario, id_bloque_horario) en BD |
| Un docente no puede tener clase en dos franjas traslapadas | `ConflictValidationService::validarDocenteOcupado()` — traslape real |
| Registro activo en `disponibilidad_docente` = docente NO disponible | `ConflictValidationService::validarDisponibilidadDocente()` |
| El mismo ciclo no puede tener dos clases traslapadas en el mismo horario | `ConflictValidationService::validarCicloTraslape()` |
| `ciclo_semestre` se resuelve via Horario → Carrera+Período → Pensum → PensumCurso | Todos los servicios que lo usan |
| Coordinador solo accede a carreras que coordina | `HorarioConsultaService::verificarCarreraCoordinador()`, `ReporteDataService` |
| Docente solo ve sus propias clases | `HorarioController::miHorario()` fuerza `id_docente` del usuario autenticado |
| Estudiante solo ve horarios publicados | `HorarioConsultaService::publicadoPorCarreraYPeriodo()` con `WHERE nombre_estado = 'publicado'` |
| Horario no editable si estado ≠ borrador o generado | `ConflictValidationService::validarEstadoHorario()` |
| No aprobar/bloquear/publicar un horario sin detalles activos | `HorarioStateService::ejecutarTransicion()` |
| Bloque destino al mover debe pertenecer a la misma carrera del horario | `EdicionManualService::moverDetalle()` |
| Fecha límite de edición del período | `ConflictValidationService::validarFechaLimite()` — revalidada dentro de transacción |
| Transiciones administrativas solo desde estados válidos | `HorarioStateService::TRANSICIONES` (constante de clase) |
| Horario bloqueado sigue siendo compromiso del docente | `bloqueado` incluido en todos los filtros de ocupación global |
| Notificaciones solo se envían si el evento fue exitoso | Verificación `if ($resultado->exitoso)` antes de llamar al servicio |
| Notificaciones no rompen el flujo del evento principal | `try/catch + Log::error()` sin relanzar |

---

## 10. Validaciones críticas implementadas

### Prioridad docente ASC
El algoritmo de generación (`GeneradorParcialService`) ordena las asignaciones por `docente.prioridad ASC` antes de procesar. El docente con prioridad 1 (alta) recibe los mejores bloques primero. Dentro de la misma prioridad, el orden es por `id_asignacion ASC` (determinista).

### Disponibilidad como bloqueo
Un registro activo en `disponibilidad_docente` significa que el docente NO puede dar clase en ese bloque. La ausencia de registro significa disponibilidad total. Se valida por traslape de tiempo, no por ID de bloque, para detectar conflictos entre bloques de distintas carreras con el mismo horario.

### Traslape real por día/hora
Todas las validaciones de conflicto usan la condición:
```
mismo id_dia
AND bloque_existente.hora_inicio < candidato.hora_fin
AND bloque_existente.hora_fin    > candidato.hora_inicio
```
Esto aplica en: `validarDisponibilidadDocente`, `validarDocenteOcupado`, `validarCicloTraslape`, filtros en memoria del `GeneradorParcialService` y de `PersistenciaHorarioService`.

### ciclo_semestre por pensum correcto
La secuencia siempre es:
1. Obtener `Horario.id_carrera` + `Horario.id_periodo_academico`
2. Buscar `Pensum` activo con esos dos valores
3. Buscar `PensumCurso` por `id_pensum` + `id_curso`
4. Usar `PensumCurso.ciclo_semestre`

Nunca se filtra solo por `id_periodo_academico` porque el mismo curso puede existir en pensums de distintas carreras con diferente `ciclo_semestre`.

### Estado del horario
`ConflictValidationService::validarEstadoHorario()` solo permite edición en `borrador` y `generado`. Los estados `aprobado`, `bloqueado` y `publicado` bloquean la edición de contenido. Las transiciones administrativas tienen su propia máquina de estados en `HorarioStateService` y no pasan por `validarEstadoHorario()`.

### Fecha límite
`validarFechaLimite()` recibe el modelo `PeriodoAcademico` hidratado (0 queries extra). En `EdicionManualService` y `PersistenciaHorarioService` se revalida dentro de la transacción cargando el período con `lockForUpdate()` para cubrir condiciones de carrera.

### Bloqueo transaccional con lockForUpdate()
Todos los servicios que escriben en `horario`, `detalle_horario` o `notificacion` usan `lockForUpdate()` sobre las filas involucradas antes de modificarlas. El orden de bloqueo es siempre: horario → período → detalle (para evitar deadlocks). En `PersistenciaHorarioService::confirmar()` se verifica la existencia de detalles activos dentro de la transacción con `lockForUpdate()` para cubrir la ventana de concurrencia entre la prevalidación y el commit.

---

## 11. Dependencias Composer necesarias

### Incluidas en Laravel 11 (no instalar manualmente)
| Paquete | Uso |
|---|---|
| `laravel/framework ^11.0` | Framework base |
| `laravel/sanctum ^4.0` | Autenticación por token Bearer |
| `illuminate/database` | Eloquent ORM y Query Builder |
| `illuminate/support` | Collections, facades, helpers |

### Instalar manualmente (no incluidas en Laravel)
| Paquete | Comando | Uso | ¿Crítico? |
|---|---|---|---|
| `barryvdh/laravel-dompdf` | `composer require barryvdh/laravel-dompdf` | Generación de reportes PDF | ⚠️ Sin esto, los endpoints de reportes PDF fallan con 500 |
| `maatwebsite/excel` | `composer require maatwebsite/excel` | Generación de reportes Excel (.xlsx) | ⚠️ Sin esto, los endpoints de reportes Excel fallan con 500 |

> Los endpoints de reportes están registrados en las rutas aunque las dependencias no estén instaladas. El error solo ocurre al momento de descargar el archivo.

---

## 12. Variables .env requeridas o recomendadas

### Base de datos (requeridas)
```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=horarios_universitarios
DB_USERNAME=horarios_user
DB_PASSWORD=password_seguro
```

### Aplicación (requeridas)
```dotenv
APP_NAME="Sistema de Horarios Universitarios"
APP_ENV=production          # local en desarrollo
APP_KEY=                    # generado con php artisan key:generate
APP_DEBUG=false             # true en desarrollo
APP_URL=https://tu-dominio.com
```

### Sanctum (requeridas para autenticación desde frontend)
```dotenv
SANCTUM_STATEFUL_DOMAINS=tu-dominio.com,localhost:5173
SESSION_DOMAIN=tu-dominio.com
```

### CORS (requeridas si frontend está en dominio diferente)
```dotenv
# En config/cors.php configurar:
# 'allowed_origins' => ['https://frontend.tu-dominio.com']
# No hay variable .env nativa para CORS en Laravel 11 — se configura en config/cors.php
```

### Negocio académico (recomendadas)
```dotenv
MAX_CURSOS_DOCENTE=6        # Límite de secciones por docente por período
```

### Logs (recomendadas para producción)
```dotenv
LOG_CHANNEL=stack
LOG_LEVEL=error             # debug en desarrollo
LOG_DEPRECATIONS_CHANNEL=null
```

### Caché y colas (opcionales)
```dotenv
CACHE_DRIVER=file           # redis en producción con alta carga
QUEUE_CONNECTION=sync       # Si se agregan colas en el futuro
```

---

## 13. Checklist de pruebas finales antes de frontend

```
AUTENTICACIÓN
[ ] Login con credenciales correctas → token recibido
[ ] Login con credenciales incorrectas → 401
[ ] Acceso a ruta protegida sin token → 401
[ ] Acceso a ruta de admin con token de coordinador → 403
[ ] POST /auth/cambiar-perfil → perfil activo actualizado
[ ] GET /auth/pregunta-seguridad/{user} → devuelve pregunta
[ ] POST /auth/recuperar-password → contraseña cambiada

CATÁLOGOS
[ ] GET /catalogos/roles → 4 registros exactos con IDs 1-4
[ ] GET /catalogos/dias  → 7 días, minúsculas sin tilde, orden correcto

GESTIÓN DE HORARIOS (flujo completo)
[ ] Crear carrera → asociar jornada → crear bloques automáticos
[ ] Crear período → pensum → cursos con ciclo_semestre
[ ] Crear secciones → asignar docentes → verificar validaciones
[ ] Docente registra disponibilidad → toggle funciona
[ ] GeneradorParcial → PersistenciaHorario::confirmar() → estado generado
[ ] Mover clase → verificar bloque de otra carrera rechazado (422)
[ ] Aprobar → Publicar → intentar editar → rechazado (422)

CONSULTAS POR ROL
[ ] Admin: GET /horarios → todos los horarios
[ ] Coordinador: GET /horarios/por-carrera?id_carrera=X → solo sus carreras
[ ] Coordinador con carrera ajena → 403
[ ] Docente: GET /docente/horario → solo sus clases
[ ] Estudiante: GET /estudiante/horario?... → solo publicados
[ ] Estudiante con horario no publicado → { "publicado": false }

NOTIFICACIONES
[ ] Aprobar horario → coordinador y docentes reciben notificación
[ ] GET /notificaciones → lista solo del usuario autenticado
[ ] PATCH /notificaciones/{id}/leer de otro usuario → 403
[ ] PATCH /notificaciones/leer-todas → todas propias marcadas

REPORTES
[ ] GET /reportes/horario-carrera?id_horario=1&formato=excel → descarga .xlsx
[ ] GET /reportes/horario-carrera?id_horario=1&formato=pdf  → descarga .pdf
[ ] GET /reportes/horario-docente (rol docente, sin id_docente) → sus clases
[ ] GET /reportes/secciones-no-asignadas → dos categorías presentes
[ ] GET /reportes/resumen-asignaciones → COUNT DISTINCT correcto
[ ] Reporte coordinador con carrera ajena → 403
```

---

## 14. Checklist de riesgos técnicos pendientes

| Riesgo | Nivel | Mitigación aplicada | Pendiente |
|---|---|---|---|
| Dependencias de reportes no instaladas | Alto | Documentado con advertencias claras | Instalar antes de primera prueba de reportes |
| Múltiples pensums activos por carrera-período | Medio | Se toma el primero activo; documentado como restricción | Coordinador debe mantener un solo pensum activo |
| Docente sin `id_usuario_coordinador` en carrera | Bajo | Filtrado de null en `NotificacionService::enviar()` | Sin pendiente |
| Concurrencia en generación simultánea | Medio | `lockForUpdate()` en confirmar(). Prevalidación fuera de transacción cubre el caso común | Si se implementan jobs de cola, revisar aislamiento |
| Credenciales admin iniciales en producción | Alto | Documentado en checklist de despliegue | Cambiar password de admin tras primer deploy |
| `APP_DEBUG=true` en producción | Alto | Documentado en checklist de despliegue | Verificar antes de exponer la API |
| Sin cifrado de `respuesta_seguridad_hash` débil | Medio | Se hashea, pero dependiente de la implementación en `AuthController` | Verificar que se use `bcrypt` o `argon2` |
| Tamaño de PDF con muchos registros | Bajo | Documentado en tabla de errores comunes | Si se excede `memory_limit`, aumentar en `php.ini` |

---

## 15. Archivos y carpetas que NO deben subirse a GitHub

### `.gitignore` recomendado para el backend

```gitignore
# Configuración de entorno — contiene credenciales
.env
.env.backup
.env.production

# Dependencias — se reinstalan con composer install
/vendor/

# Node (si aplica para compilación de assets)
/node_modules/

# Archivos de sistema macOS
.DS_Store
__MACOSX/
*.DS_Store

# Caché de Laravel (se regeneran con artisan)
/bootstrap/cache/*.php
/storage/framework/cache/
/storage/framework/sessions/
/storage/framework/views/
/storage/logs/

# Archivos de IDE y editores
.idea/
.vscode/
*.swp
*.swo
Thumbs.db

# Archivos de testing local
/coverage/
.phpunit.result.cache

# Archivos de construcción
/public/hot
/public/storage
```

> El archivo `.env.example` sí debe subirse — sirve como plantilla sin valores reales.

---

## 16. Recomendación de estructura para iniciar frontend

El frontend puede ser cualquier framework SPA (React, Vue, Angular) o SSR (Next.js, Nuxt). La API es agnóstica al frontend.

### Estructura sugerida de módulos del frontend

```
frontend/
├── src/
│   ├── api/                    ← Llamadas HTTP a la API (axios/fetch)
│   │   ├── auth.js             ← login, logout, me, cambiarPerfil
│   │   ├── horarios.js         ← CRUD y consultas de horarios
│   │   ├── docentes.js         ← disponibilidad, perfil
│   │   ├── notificaciones.js   ← lista, leer, eliminar
│   │   └── reportes.js         ← descarga PDF/Excel
│   ├── stores/                 ← Estado global (Pinia/Redux/Vuex)
│   │   ├── auth.js             ← token, perfil, rol activo
│   │   └── notificaciones.js   ← contador no leídas
│   ├── views/                  ← Páginas por rol
│   │   ├── admin/
│   │   ├── coordinador/
│   │   ├── docente/
│   │   └── estudiante/
│   └── components/             ← Componentes reutilizables
│       ├── TablaHorario.vue
│       ├── CalendarioSemanal.vue
│       └── BloqueDisponibilidad.vue
```

### Flujo de autenticación recomendado

1. `POST /auth/login` → guardar token en `localStorage` o cookie segura
2. `GET /auth/me` → cargar perfil, roles y `ultimo_perfil_activo`
3. Redirigir según `ultimo_perfil_activo` al dashboard correspondiente
4. Interceptor HTTP: adjuntar `Authorization: Bearer {token}` en cada request
5. En 401: redirigir a login y limpiar token almacenado

---

## 17. Endpoints prioritarios para el frontend

Consumir en este orden para tener las vistas principales operativas rápidamente:

### Prioridad 1 — Autenticación (necesario para todo lo demás)
```
POST /api/auth/login
GET  /api/auth/me
POST /api/auth/logout
POST /api/auth/cambiar-perfil
GET  /api/catalogos/roles
GET  /api/catalogos/jornadas
GET  /api/catalogos/dias
```

### Prioridad 2 — Vista principal por rol
```
# Admin / Coordinador
GET /api/horarios?id_periodo_academico=X      ← listado de horarios
GET /api/horarios/{id}/completo               ← tabla de horario
GET /api/horarios/{id}/transiciones           ← qué botones mostrar

# Docente
GET /api/docente/horario                      ← su horario personal
GET /api/perfil/docente                       ← datos del perfil

# Estudiante
GET /api/estudiante/horario?id_carrera=X&id_periodo_academico=Y
```

### Prioridad 3 — Gestión activa
```
GET  /api/periodos-academicos?es_vigente=true
GET  /api/carreras
GET  /api/secciones?id_periodo_academico=X
POST /api/secciones/{id}/asignacion
POST /api/docentes/{id}/disponibilidad/toggle
PATCH /api/horarios/{id}/aprobar
PATCH /api/horarios/{id}/publicar
```

### Prioridad 4 — Notificaciones (complementario)
```
GET  /api/notificaciones/no-leidas            ← badge de contador
GET  /api/notificaciones
PATCH /api/notificaciones/leer-todas
```

### Prioridad 5 — Reportes (cuando el resto funciona)
```
GET /api/reportes/horario-carrera?id_horario=X&formato=excel
GET /api/reportes/horario-docente?formato=pdf
```

---

## 18. Confirmación de estado de la base de datos

**No se requiere ninguna modificación a la base de datos antes de iniciar el frontend**, siempre que:

1. `php artisan migrate` se haya ejecutado con todas las 23 migraciones.
2. `php artisan db:seed` haya sembrado los catálogos fijos.
3. Las dependencias de reportes estén instaladas si se usarán los endpoints de reportes.

Las únicas situaciones que requerirían una nueva migración son:

| Situación | Acción requerida |
|---|---|
| Se descubren campos faltantes en pruebas reales del frontend | Nueva migración justificada, sin modificar existentes |
| Se requiere un nuevo tipo de notificación no cubierto por el ENUM actual | Modificación del ENUM en `notificacion.tipo_notificacion` con nueva migración |
| Se requieren índices de rendimiento adicionales | Nueva migración solo con `CREATE INDEX` |

Cualquier otra modificación que altere tablas existentes, cambie tipos de datos o modifique restricciones debe ser justificada y aprobada explícitamente, siguiendo el mismo criterio que se aplicó durante los 4 sprints.

---

*Documento generado al cierre del Sprint 4. El backend está aprobado y listo para ser consumido por el frontend.*
