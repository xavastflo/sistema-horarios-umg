# API Reference — Sistema de Horarios Universitarios
## Sprints 1–4 | Estado final

**Base URL:** `https://tu-dominio.com/api`  
**Autenticación:** Bearer Token (Laravel Sanctum)  
**Content-Type:** `application/json`

---

## Leyenda de roles

| Símbolo | Rol | Descripción |
|---|---|---|
| 🔓 | Público | Sin token |
| 👑 | administrador | Acceso total |
| 🎓 | administrador + coordinador | Gestión académica |
| 📚 | docente | Solo su propio contenido |
| 🎒 | estudiante | Solo horarios publicados |
| 🔐 | Cualquier autenticado | Todos los roles |

---

## AUTENTICACIÓN

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| POST | `/auth/login` | 🔓 | Iniciar sesión. Devuelve token Bearer |
| POST | `/auth/logout` | 🔐 | Cerrar sesión. Invalida el token |
| GET | `/auth/me` | 🔐 | Perfil del usuario autenticado |
| POST | `/auth/cambiar-perfil` | 🔐 | Cambiar rol activo (multi-rol) |
| GET | `/auth/pregunta-seguridad/{nombre_usuario}` | 🔓 | Obtener pregunta de seguridad |
| POST | `/auth/recuperar-password` | 🔓 | Recuperar contraseña con respuesta de seguridad |

### Parámetros

**POST /auth/login**
```json
{ "nombre_usuario": "admin", "password": "Admin@2024!" }
```

**POST /auth/cambiar-perfil**
```json
{ "nombre_rol": "coordinador" }
```

**POST /auth/recuperar-password**
```json
{
  "nombre_usuario": "jperez",
  "respuesta": "mi-respuesta",
  "nueva_password": "NuevoPass2024!",
  "nueva_password_confirmation": "NuevoPass2024!"
}
```

---

## CATÁLOGOS (públicos)

| Método | URI | Descripción |
|---|---|---|
| GET | `/catalogos/roles` | Lista de roles del sistema |
| GET | `/catalogos/jornadas` | Lista de jornadas |
| GET | `/catalogos/dias` | Días de la semana (nombre en minúsculas sin tilde) |
| GET | `/catalogos/estados-horario` | Estados del ciclo de vida del horario |

---

## USUARIOS

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/usuarios` | 👑 | Listar. Filtros: `estado`, `buscar`, `id_rol` |
| POST | `/usuarios` | 👑 | Crear usuario |
| GET | `/usuarios/{id}` | 👑 | Ver usuario |
| PUT | `/usuarios/{id}` | 👑 | Actualizar usuario |
| DELETE | `/usuarios/{id}` | 👑 | Desactivar usuario |
| POST | `/usuarios/{id}/roles` | 👑 | Asignar rol: `{ "id_rol": 2 }` |
| DELETE | `/usuarios/{id}/roles/{id_rol}` | 👑 | Quitar rol |

---

## FACULTADES

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/facultades` | 👑 | Listar. Filtros: `estado`, `buscar` |
| POST | `/facultades` | 👑 | Crear |
| GET | `/facultades/{id}` | 👑 | Ver con carreras activas |
| PUT | `/facultades/{id}` | 👑 | Actualizar |
| DELETE | `/facultades/{id}` | 👑 | Desactivar (solo sin carreras activas) |

---

## CARRERAS

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/carreras` | 🎓 | Listar. Filtros: `estado`, `id_facultad`, `buscar` |
| POST | `/carreras` | 👑 | Crear |
| GET | `/carreras/{id}` | 🎓 | Ver con jornadas y coordinador |
| PUT | `/carreras/{id}` | 👑 | Actualizar |
| DELETE | `/carreras/{id}` | 👑 | Desactivar |
| POST | `/carreras/{id}/coordinador` | 👑 | Asignar coordinador: `{ "id_usuario": 5 }` |
| DELETE | `/carreras/{id}/coordinador` | 👑 | Desasignar coordinador |
| POST | `/carreras/{id}/jornadas` | 🎓 | Asociar jornadas: `{ "jornadas": [1,2] }` |

---

## DOCENTES

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/docentes` | 🎓 | Listar por prioridad ASC. Filtros: `estado`, `prioridad`, `buscar` |
| POST | `/docentes` | 🎓 | Crear perfil docente |
| GET | `/docentes/{id}` | 🎓 | Ver docente |
| PUT | `/docentes/{id}` | 🎓 | Actualizar |
| DELETE | `/docentes/{id}` | 🎓 | Desactivar |
| PATCH | `/docentes/{id}/prioridad` | 🎓 | Cambiar prioridad (1=alta, 2=media, 3=baja) |
| GET | `/perfil/docente` | 📚 | Perfil del docente autenticado |

---

## PERÍODOS ACADÉMICOS

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/periodos-academicos` | 🎓 | Listar. Filtros: `estado`, `anio`, `es_vigente` |
| POST | `/periodos-academicos` | 🎓 | Crear |
| GET | `/periodos-academicos/{id}` | 🎓 | Ver |
| PUT | `/periodos-academicos/{id}` | 🎓 | Actualizar |
| DELETE | `/periodos-academicos/{id}` | 🎓 | Cerrar (solo si en `planificacion`) |
| PATCH | `/periodos-academicos/{id}/marcar-vigente` | 🎓 | Marcar como vigente (desmarca los demás) |

---

## CURSOS

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/cursos` | 🎓 | Listar. Filtros: `estado`, `buscar` |
| POST | `/cursos` | 🎓 | Crear |
| GET | `/cursos/{id}` | 🎓 | Ver |
| PUT | `/cursos/{id}` | 🎓 | Actualizar |
| DELETE | `/cursos/{id}` | 🎓 | Desactivar (solo sin secciones activas) |

---

## PENSUMS

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/pensums` | 🎓 | Listar. Filtros: `estado`, `id_carrera`, `id_periodo_academico` |
| POST | `/pensums` | 🎓 | Crear |
| GET | `/pensums/{id}` | 🎓 | Ver con cursos por ciclo |
| PUT | `/pensums/{id}` | 🎓 | Actualizar |
| DELETE | `/pensums/{id}` | 🎓 | Desactivar |
| GET | `/pensums/{id}/cursos` | 🎓 | Listar cursos del pensum |
| POST | `/pensums/{id}/cursos` | 🎓 | Agregar curso: `{ "id_curso": 1, "ciclo_semestre": 3 }` |
| PATCH | `/pensums/{id}/cursos/{pc}` | 🎓 | Cambiar ciclo: `{ "ciclo_semestre": 5 }` |
| DELETE | `/pensums/{id}/cursos/{pc}` | 🎓 | Quitar curso del pensum |

---

## BLOQUES HORARIOS

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/bloques-horario` | 🎓 | Listar. Filtros: `id_carrera_jornada`, `id_dia`, `estado` |
| POST | `/bloques-horario` | 🎓 | Crear bloque individual |
| GET | `/bloques-horario/{id}` | 🎓 | Ver bloque |
| DELETE | `/bloques-horario/{id}` | 🎓 | Desactivar (si no está en uso) |
| POST | `/bloques-horario/generar` | 🎓 | **Generación automática** por rango horario y días |
| GET | `/carrera-jornadas/{id}/bloques` | 🎓 | Bloques de una carrera-jornada, agrupados por día |

**POST /bloques-horario/generar — parámetros:**
```json
{
  "id_carrera_jornada": 1,
  "ids_dia": [1, 2, 3, 4, 5],
  "hora_inicio_general": "18:00",
  "hora_fin_general": "21:00",
  "duracion_minutos": 90,
  "exclusiones": [{ "inicio": "13:00", "fin": "14:00" }]
}
```

---

## DISPONIBILIDAD DOCENTE

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/docentes/{id}/disponibilidad` | 🎓📚 | Listar bloques NO disponibles del docente |
| POST | `/docentes/{id}/disponibilidad` | 📚 | Marcar bloque como no disponible |
| DELETE | `/docentes/{id}/disponibilidad/{disp}` | 📚 | Desmarcar bloque |
| POST | `/docentes/{id}/disponibilidad/toggle` | 📚 | Marcar/desmarcar en una sola llamada |

> **Regla:** registro activo = docente NO disponible en ese bloque.

---

## SECCIONES

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/secciones` | 🎓 | Listar. Filtros: `id_curso`, `id_periodo_academico`, `estado` |
| POST | `/secciones` | 🎓 | Crear |
| GET | `/secciones/{id}` | 🎓 | Ver con asignación activa |
| DELETE | `/secciones/{id}` | 🎓 | Desactivar (sin docente asignado) |
| GET | `/secciones/{id}/asignacion` | 🎓 | Consultar asignación activa |
| POST | `/secciones/{id}/asignacion` | 🎓 | Asignar docente: `{ "id_docente": 3 }` |
| DELETE | `/secciones/{id}/asignacion` | 🎓 | Quitar docente |

---

## ASIGNACIONES

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/asignaciones` | 🎓 | Listar. Filtros: `id_docente`, `id_periodo_academico`, `estado` |
| GET | `/asignaciones/{id}` | 🎓 | Ver asignación |
| GET | `/asignaciones/docente/{id}/periodo/{id_p}` | 🎓 | Asignaciones de un docente en un período |

---

## HORARIOS — Consulta

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/horarios` | 🎓 | Listar. Filtros: `id_carrera`, `id_periodo_academico`, `estado` |
| GET | `/horarios/por-carrera` | 🎓 | Por carrera y período: `?id_carrera=1&id_periodo_academico=1` |
| GET | `/horarios/{id}` | 🎓 | Ver horario con estado y conteo de detalles |
| GET | `/horarios/{id}/detalles` | 🎓 | Detalles básicos (curso, docente, día, hora) |
| GET | `/horarios/{id}/completo` | 🎓 | Detalles enriquecidos con jornada, carrera y `ciclo_semestre` |
| GET | `/horarios/{id}/transiciones` | 🎓 | Acciones disponibles desde el estado actual |
| GET | `/docente/horario` | 📚 | Clases del docente autenticado (todos sus horarios) |
| GET | `/estudiante/horario` | 🎒 | Horario publicado: `?id_carrera=1&id_periodo_academico=1` |

---

## HORARIOS — Edición manual

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| PATCH | `/horarios/{id}/detalles/{det}/mover` | 🎓 | Mover clase a otro bloque: `{ "id_bloque_horario": 7 }` |
| DELETE | `/horarios/{id}/detalles/{det}` | 🎓 | Eliminar clase del horario. Body opcional: `{ "motivo": "..." }` |

---

## HORARIOS — Transiciones administrativas

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| PATCH | `/horarios/{id}/aprobar` | 👑 | `generado → aprobado`. Body opcional: `{ "observaciones": "..." }` |
| PATCH | `/horarios/{id}/bloquear` | 👑 | `aprobado → bloqueado` |
| PATCH | `/horarios/{id}/publicar` | 👑 | `aprobado/bloqueado → publicado` (estado terminal) |

---

## NOTIFICACIONES

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/notificaciones` | 🔐 | Notificaciones activas del usuario autenticado |
| GET | `/notificaciones/no-leidas` | 🔐 | Solo no leídas |
| PATCH | `/notificaciones/leer-todas` | 🔐 | Marcar todas propias como leídas |
| PATCH | `/notificaciones/{id}/leer` | 🔐 | Marcar una como leída (403 si no es propia) |
| DELETE | `/notificaciones/{id}` | 🔐 | Estado → inactivo (403 si no es propia) |

> **Regla:** cada usuario solo puede ver y modificar sus propias notificaciones.

---

## REPORTES PDF / EXCEL

> ⚠️ **Dependencias requeridas.** Si no están instaladas, estos endpoints fallan con error 500:
> ```bash
> composer require barryvdh/laravel-dompdf
> composer require maatwebsite/excel
> ```

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/reportes/horario-carrera` | 🎓 | Horario de una carrera/período (versión específica) |
| GET | `/reportes/horario-docente` | 🎓📚 | Horario de un docente |
| GET | `/reportes/secciones-no-asignadas` | 🎓 | Secciones sin docente o sin bloque en el horario |
| GET | `/reportes/resumen-asignaciones` | 🎓 | Carga docente: secciones y bloques por período |

**Parámetro común:** `?formato=pdf` o `?formato=excel` (default: `excel`)

**Parámetros por reporte:**

| Reporte | Parámetros requeridos | Opcionales |
|---|---|---|
| `horario-carrera` | `id_horario` | `formato` |
| `horario-docente` | `id_docente` (admin/coord); ignorado si rol=docente | `id_periodo_academico`, `id_carrera`, `formato` |
| `secciones-no-asignadas` | `id_carrera`, `id_periodo_academico`, `id_horario` | `formato` |
| `resumen-asignaciones` | `id_carrera`, `id_periodo_academico` | `id_horario`, `formato` |

---

## HISTORIAL

| Método | URI | Acceso | Descripción |
|---|---|---|---|
| GET | `/historial` | 👑 | Listar. Filtros: `tabla`, `tipo_cambio`, `id_usuario`, `fecha_desde`, `fecha_hasta` |
| GET | `/historial/{tabla}/{id}` | 👑 | Historial de un registro específico |

---

## Total de endpoints

| Grupo | Cantidad |
|---|---|
| Autenticación | 6 |
| Catálogos | 4 |
| Usuarios | 7 |
| Facultades | 5 |
| Carreras | 8 |
| Docentes | 7 |
| Períodos académicos | 6 |
| Cursos | 5 |
| Pensums | 9 |
| Bloques horarios | 6 |
| Disponibilidad docente | 4 |
| Secciones | 7 |
| Asignaciones | 3 |
| Horarios (consulta) | 8 |
| Horarios (edición manual) | 2 |
| Horarios (transiciones admin) | 3 |
| Notificaciones | 5 |
| Reportes | 4 |
| Historial | 2 |
| **Total** | **106** |
