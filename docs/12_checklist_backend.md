# Checklist Backend — Sistema de Horarios Universitarios
## Sprints 1–4 | Antes de frontend y despliegue

---

## 1. Orden recomendado de pruebas

Ejecutar en este orden para respetar dependencias entre módulos.

### Fase 1 — Base (sin datos previos)
```
[ ] POST /auth/login                              → token Bearer recibido
[ ] GET  /auth/pregunta-seguridad/{nombre_usuario}→ devuelve pregunta
[ ] GET  /catalogos/roles                         → 4 roles (admin, coord, docente, estudiante)
[ ] GET  /catalogos/jornadas                      → matutina, vespertina, fin_de_semana
[ ] GET  /catalogos/dias                          → lunes–domingo en minúsculas sin tilde
[ ] GET  /catalogos/estados-horario               → borrador, generado, aprobado, bloqueado, publicado
```

### Fase 2 — Estructura organizacional
```
[ ] POST /facultades                              → crear 1 facultad
[ ] POST /carreras                                → crear 1 carrera con id_facultad
[ ] POST /usuarios  (para coordinador)            → nombre_usuario único
[ ] POST /usuarios/{id}/roles  { id_rol: 2 }      → asignar rol coordinador
[ ] POST /carreras/{id}/coordinador               → asignar coordinador
[ ] POST /carreras/{id}/jornadas { jornadas:[2] } → asociar jornada vespertina
[ ] GET  /carreras/{id}                           → confirmar carrera_jornada creada
```

### Fase 3 — Estructura académica
```
[ ] POST /periodos-academicos                     → estado: planificacion
[ ] PATCH /periodos-academicos/{id}/marcar-vigente→ es_vigente = true
[ ] POST /cursos  × N cursos                      → codigo_curso único
[ ] POST /pensums                                 → id_carrera + id_periodo_academico
[ ] POST /pensums/{id}/cursos × N                 → ciclo_semestre obligatorio
[ ] GET  /pensums/{id}                            → confirmar cursos con ciclo
```

### Fase 4 — Docentes y disponibilidad
```
[ ] POST /usuarios (para docente)
[ ] POST /usuarios/{id}/roles { id_rol: 3 }
[ ] POST /docentes { id_usuario, codigo_docente, prioridad }
[ ] POST /docentes/{id}/disponibilidad { id_bloque_horario }   → bloqueo activo
[ ] POST /docentes/{id}/disponibilidad/toggle                  → ciclo ON/OFF
[ ] GET  /docentes/{id}/disponibilidad                         → lista de bloqueos
```

### Fase 5 — Bloques horarios
```
[ ] POST /bloques-horario/generar (sin exclusiones)   → bloques creados
[ ] POST /bloques-horario/generar (con exclusiones)   → bloques respetan almuerzo
[ ] GET  /carrera-jornadas/{id}/bloques               → agrupados por día
[ ] POST /bloques-horario/generar (segunda vez)       → omitidos, sin error
```

### Fase 6 — Secciones y asignaciones
```
[ ] POST /secciones × N (una por curso del pensum)
[ ] POST /secciones/{id}/asignacion { id_docente }    → validaciones:
    [ ] Sección sin docente → 201
    [ ] Misma sección dos veces → 422
    [ ] MAX_CURSOS_DOCENTE excedido → 422
    [ ] Mismo ciclo dos veces → 422
[ ] DELETE /secciones/{id}/asignacion                 → quitar docente
[ ] GET  /asignaciones/docente/{id}/periodo/{id_p}    → puede_asignar_mas
```

### Fase 7 — Generación y persistencia (requiere código de generación)
```
[ ] GeneradorParcialService::generar()                → propuestas en memoria
[ ] PersistenciaHorarioService::confirmar()           → detalle_horario insertados
[ ] GET /horarios/{id}/detalles                       → detalles activos
[ ] GET /horarios/{id}/completo                       → ciclo_semestre resuelto
[ ] GET /horarios/{id}/transiciones                   → [ "aprobar" ]
```

### Fase 8 — Edición manual
```
[ ] PATCH /horarios/{id}/detalles/{det}/mover         → bloque destino misma carrera
[ ] PATCH /horarios/{id}/detalles/{det}/mover         → bloque de otra carrera → 422
[ ] DELETE /horarios/{id}/detalles/{det}              → clase eliminada
[ ] PATCH /horarios/{id}/detalles/{det}/mover         → horario aprobado → 422
```

### Fase 9 — Transiciones de estado
```
[ ] PATCH /horarios/{id}/aprobar                      → generado → aprobado
[ ] PATCH /horarios/{id}/aprobar  (de nuevo)          → 422 transicion_invalida
[ ] PATCH /horarios/{id}/bloquear                     → aprobado → bloqueado
[ ] PATCH /horarios/{id}/publicar                     → bloqueado → publicado
[ ] PATCH /horarios/{id}/aprobar  (publicado)         → 422 estado terminal
[ ] PATCH /horarios/{id}/detalles/{det}/mover         → horario publicado → 422
```

### Fase 10 — Consultas por rol
```
[ ] GET /horarios?estado=publicado                    → admin ve todos
[ ] GET /horarios/por-carrera?id_carrera=1&id_periodo_academico=1  → coord solo sus carreras
[ ] GET /docente/horario                              → docente ve solo sus clases
[ ] GET /estudiante/horario?id_carrera=1&id_periodo_academico=1    → solo publicados
[ ] GET /horarios/{id}/completo (coordinador otra carrera) → 403
```

### Fase 11 — Notificaciones
```
[ ] GET  /notificaciones                              → lista del usuario autenticado
[ ] GET  /notificaciones/no-leidas                   → solo no leídas
[ ] PATCH /notificaciones/{id}/leer                  → leida = true, fecha_lectura
[ ] PATCH /notificaciones/{id}/leer (de otro usuario)→ 403
[ ] PATCH /notificaciones/leer-todas                 → actualizadas = N
[ ] DELETE /notificaciones/{id}                      → estado = inactivo
```

### Fase 12 — Reportes
```
[ ] GET /reportes/horario-carrera?id_horario=1&formato=excel     → descarga .xlsx
[ ] GET /reportes/horario-carrera?id_horario=1&formato=pdf       → descarga .pdf
[ ] GET /reportes/horario-docente?formato=excel (rol docente)    → sin id_docente
[ ] GET /reportes/secciones-no-asignadas?id_carrera=1&...        → 2 categorías en Excel
[ ] GET /reportes/resumen-asignaciones?id_carrera=1&...          → COUNT DISTINCT correcto
[ ] GET /reportes/secciones-no-asignadas (coord otra carrera)    → 403
```

---

## 2. Checklist backend antes de pasar a frontend

### Datos iniciales
```
[ ] php artisan migrate:fresh --seed ejecuta sin errores
[ ] Roles sembrados: administrador(1), coordinador(2), docente(3), estudiante(4)
[ ] Jornadas sembradas: matutina(1), vespertina(2), fin_de_semana(3)
[ ] Días sembrados: lunes(1)–domingo(7), en minúsculas sin tilde
[ ] Estados horario: borrador(1), generado(2), aprobado(3), bloqueado(4), publicado(5)
[ ] Usuario admin creado: nombre_usuario=admin, password=Admin@2024!
```

### Autenticación y seguridad
```
[ ] Sanctum configurado y tokens funcionan
[ ] Middleware CheckRol implementado y registrado
[ ] Rutas de escritura rechazan token inválido con 401
[ ] Coordinador no accede a carreras que no coordina (403)
[ ] Docente no ve notificaciones de otros usuarios (403)
[ ] Estudiante solo ve horarios publicados
```

### Validaciones de negocio
```
[ ] Disponibilidad por traslape real (dia + hora), no por id_bloque
[ ] Docente ocupado globalmente por traslape (entre carreras distintas)
[ ] ciclo_semestre resuelto via Horario → Carrera+Período → Pensum → PensumCurso
[ ] MAX_CURSOS_DOCENTE configurable por .env (default: 6)
[ ] Un docente no repite ciclo_semestre por período
[ ] Sección tiene máximo un docente activo (UNIQUE en BD)
[ ] Bloque horario máximo una vez por horario (UNIQUE en BD)
```

### Transacciones y concurrencia
```
[ ] lockForUpdate() en confirmar() y limpiarDetalles()
[ ] Verificación de detalles activos dentro de la transacción en confirmar()
[ ] lockForUpdate() en moverDetalle() y eliminarDetalle()
[ ] lockForUpdate() sobre período en EdicionManualService
[ ] Validación de bloque pertenece a la carrera del horario en moverDetalle()
```

### Notificaciones
```
[ ] NotificacionService inyectable (no estático)
[ ] try/catch + Log::error() en todos los puntos de integración
[ ] Notificaciones después del commit (no dentro de la transacción)
[ ] Solo se envían si el evento principal fue exitoso
[ ] Deduplicación de destinatarios con array_unique
[ ] Filtrado de id_usuario null
```

### Historial
```
[ ] Toda operación de escritura registra en historial_cambios
[ ] id_usuario NOT NULL en historial_cambios
[ ] Valor anterior y nuevo serializados como JSON
```

### Reportes
```
[ ] composer require barryvdh/laravel-dompdf  instalado
[ ] composer require maatwebsite/excel         instalado
[ ] Vistas Blade en resources/views/reportes/  existen
[ ] Clases Export en app/Exports/              existen
[ ] Reporte horario-docente usa id_docente propio si rol=docente
[ ] ciclo_semestre en reportes resuelto por id_curso + id_pensum
[ ] Validación id_horario pertenece a carrera+período en secciones y resumen
```

---

## 3. Checklist de despliegue en VPS

### Servidor
```
[ ] PHP 8.2+ instalado
[ ] Extensiones PHP: mbstring, pdo_mysql, openssl, tokenizer, xml, ctype, json, bcmath, gd (PDF)
[ ] MySQL 8.0+ en ejecución
[ ] Nginx o Apache configurado como proxy
[ ] PHP-FPM activo y configurado
[ ] Certificado SSL (Let's Encrypt recomendado)
[ ] Firewall: abrir puertos 80 y 443; cerrar 3306 al exterior
```

### Código y dependencias
```
[ ] git clone del repositorio en /var/www/horarios
[ ] composer install --no-dev --optimize-autoloader
[ ] cp .env.example .env && php artisan key:generate
[ ] .env configurado: DB_*, APP_URL, SANCTUM_STATEFUL_DOMAINS
[ ] Variables de entorno: MAX_CURSOS_DOCENTE, APP_DEBUG=false, APP_ENV=production
[ ] Permisos: chown -R www-data:www-data storage bootstrap/cache
[ ] chmod -R 775 storage bootstrap/cache
```

### Base de datos
```
[ ] Base de datos creada: CREATE DATABASE horarios_universitarios CHARACTER SET utf8mb4
[ ] Usuario de BD creado con permisos solo a esa base
[ ] php artisan migrate
[ ] php artisan db:seed
[ ] Verificar: admin creado, roles, días y estados sembrados
```

### Optimización para producción
```
[ ] php artisan config:cache
[ ] php artisan route:cache
[ ] php artisan view:cache
[ ] php artisan optimize
[ ] APP_DEBUG=false en .env
[ ] LOG_CHANNEL=stack o configurar log rotation
```

### Verificación post-despliegue
```
[ ] POST /api/auth/login → devuelve token (conexión BD OK)
[ ] GET  /api/catalogos/roles → datos sembrados OK
[ ] Certificado SSL activo (https sin advertencia)
[ ] php artisan route:list → 106 rutas registradas
[ ] Reportes: descargar un PDF y un Excel para confirmar dependencias
```

---

## 4. Checklist de dependencias Composer

### Dependencias base (incluidas en Laravel 11)
```
[ ] laravel/framework ^11.0
[ ] laravel/sanctum ^4.0         (autenticación por token)
[ ] illuminate/database          (Eloquent, Query Builder)
```

### Dependencias de reportes (instalar manualmente)
```
[ ] barryvdh/laravel-dompdf      → PDF
    composer require barryvdh/laravel-dompdf

[ ] maatwebsite/excel            → Excel (.xlsx)
    composer require maatwebsite/excel
```

> ⚠️ **Si estas dependencias no están instaladas**, los endpoints `/api/reportes/*`
> fallarán con **HTTP 500** al intentar usar `Pdf::` o `Excel::`.
> Las rutas estarán registradas pero las clases no existirán.

### Publicar configuración (recomendado una vez)
```bash
php artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider"
php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider" --tag=config
```

### Verificar instalación
```bash
# Debe mostrar las versiones instaladas:
composer show barryvdh/laravel-dompdf
composer show maatwebsite/excel
```

---

## 5. Instrucciones para habilitar reportes PDF/Excel

**Paso 1 — Instalar dependencias:**
```bash
cd /ruta/al/proyecto/backend
composer require barryvdh/laravel-dompdf
composer require maatwebsite/excel
```

**Paso 2 — Publicar configuración (opcional):**
```bash
php artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider"
php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider" --tag=config
```

**Paso 3 — Verificar autodetección en Laravel 11:**

En Laravel 11 los service providers se autodetectan. Si no funcionan, agregar en `bootstrap/providers.php`:
```php
return [
    // ...providers existentes...
    Barryvdh\DomPDF\ServiceProvider::class,
    Maatwebsite\Excel\ExcelServiceProvider::class,
];
```

**Paso 4 — Configurar fuente DomPDF (caracteres con tildes):**

En `config/dompdf.php` (después de publicar):
```php
'options' => [
    'defaultFont'     => 'DejaVu Sans',
    'isRemoteEnabled' => false,
],
```

**Paso 5 — Probar:**
```bash
# Excel (no requiere configuración adicional)
curl -H "Authorization: Bearer TOKEN" \
  "https://tu-dominio.com/api/reportes/horario-carrera?id_horario=1&formato=excel" \
  --output horario.xlsx

# PDF
curl -H "Authorization: Bearer TOKEN" \
  "https://tu-dominio.com/api/reportes/horario-carrera?id_horario=1&formato=pdf" \
  --output horario.pdf
```

**Errores comunes:**

| Error | Causa | Solución |
|---|---|---|
| `Class "Pdf" not found` | DomPDF no instalado | `composer require barryvdh/laravel-dompdf` |
| `Class "Excel" not found` | Maatwebsite no instalado | `composer require maatwebsite/excel` |
| Tildes no renderizan en PDF | Fuente incorrecta | Configurar `defaultFont: 'DejaVu Sans'` |
| Memoria agotada en PDF grande | Tabla con muchos registros | Aumentar `memory_limit` en `php.ini` |
| Archivo Excel vacío | Datos vacíos | Verificar parámetros de la request |
