# Errores, Ejemplos JSON y Flujo del Sistema

---

## Códigos de error HTTP

| Código | Cuándo ocurre |
|---|---|
| `200` | Operación exitosa (GET, PUT, PATCH) |
| `201` | Recurso creado (POST) |
| `401` | Sin token o token expirado |
| `403` | Token válido pero sin permisos (rol incorrecto, o recurso de otro usuario) |
| `404` | Recurso no encontrado o inactivo |
| `409` | Conflicto de horario (bloque ocupado, docente ocupado, ciclo traslapado) |
| `422` | Error de validación de datos |
| `500` | Error interno (incluye dependencias de reporte no instaladas) |

---

## Estructura de error estándar

### 401
```json
{ "message": "Unauthenticated." }
```

### 403
```json
{ "message": "No tiene permisos para esta acción." }
```

### 404
```json
{ "message": "Horario no encontrado." }
```

### 422 — Validación
```json
{
  "message": "Error de validación.",
  "errors": {
    "id_carrera": ["El campo id_carrera es requerido."],
    "fecha_fin": ["La fecha de fin debe ser posterior a la de inicio."]
  }
}
```

### 422 — Regla de negocio
```json
{
  "message": "Esta sección ya tiene un docente asignado. Quite la asignación actual primero."
}
```

### 409 — Conflicto de horario
```json
{
  "exitoso": false,
  "mensaje": "Conflicto detectado al persistir: El docente ya tiene clase el lunes 18:00-19:30 (en carrera: Administración).",
  "propuesta_conflictiva": { "id_seccion": 3, "nombre_curso": "Matemática I", ... },
  "conflicto": {
    "es_valido": false,
    "conflictos": [
      {
        "tipo": "docente_ocupado",
        "mensaje": "El docente ya tiene clase el lunes 18:00-19:30 (en carrera: Administración).",
        "contexto": { "id_docente": 3, "dia": "lunes", "hora_inicio": "18:00:00", "hora_fin": "19:30:00" }
      }
    ]
  }
}
```

---

## Tipos de conflicto de horario

| Tipo | Descripción |
|---|---|
| `fecha_limite_vencida` | El período superó la fecha límite de edición |
| `horario_no_editable` | Estado del horario no permite edición (aprobado/bloqueado/publicado) |
| `docente_no_disponible` | El docente marcó ese bloque como no disponible |
| `docente_ocupado` | El docente ya tiene clase en esa franja (en cualquier carrera) |
| `ciclo_traslape` | Ya existe otro curso del mismo `ciclo_semestre` en esa franja |
| `bloque_ocupado_en_horario` | El bloque ya está asignado a otra sección en este horario |

---

## Ejemplos JSON clave

### Login exitoso
```json
{
  "token": "1|AbCdEf...",
  "tipo_token": "Bearer",
  "usuario": {
    "id_usuario": 1,
    "nombre_usuario": "admin",
    "perfil_activo": "administrador",
    "roles": [{ "id_rol": 1, "nombre_rol": "administrador" }]
  }
}
```

### Generación de bloques automática
**Request:**
```json
{
  "id_carrera_jornada": 2,
  "ids_dia": [1, 2, 3, 4, 5],
  "hora_inicio_general": "18:00",
  "hora_fin_general": "21:00",
  "duracion_minutos": 90,
  "exclusiones": [{ "inicio": "13:00", "fin": "14:00" }]
}
```
**Response 201:**
```json
{
  "message": "Se generaron 10 bloques correctamente.",
  "total_creados": 10,
  "omitidos": []
}
```

### Toggle disponibilidad docente
**Request:** `POST /api/docentes/3/disponibilidad/toggle`
```json
{ "id_bloque_horario": 5 }
```
**Response (marcado como no disponible):**
```json
{ "message": "Bloque marcado como no disponible.", "disponible": false }
```

### Aprobar horario
**Request:** `PATCH /api/horarios/1/aprobar`
```json
{ "observaciones": "Revisado y aprobado en reunión de facultad." }
```
**Response 200:**
```json
{
  "exitoso": true,
  "mensaje": "Horario actualizado de 'generado' a 'aprobado' correctamente.",
  "estado_anterior": "generado",
  "estado_nuevo": "aprobado",
  "horario": {
    "id_horario": 1,
    "fecha_aprobacion": "2024-08-10 09:00:00"
  }
}
```

### Transición inválida
**Request:** `PATCH /api/horarios/1/aprobar` (horario ya publicado)
```json
{}
```
**Response 422:**
```json
{
  "exitoso": false,
  "mensaje": "No se puede ejecutar 'aprobar' desde el estado 'publicado'. Transiciones posibles desde este estado: ninguna (estado terminal).",
  "tipo": "transicion_invalida"
}
```

### Mover clase
**Request:** `PATCH /api/horarios/1/detalles/5/mover`
```json
{ "id_bloque_horario": 12 }
```
**Response 200:**
```json
{
  "exitoso": true,
  "mensaje": "Clase movida correctamente al nuevo bloque.",
  "detalle": {
    "id_detalle_horario": 5,
    "id_bloque_horario": 12,
    "id_dia": 2
  }
}
```

### Horario del docente autenticado
**Request:** `GET /api/docente/horario`  
**Response 200:**
```json
{
  "id_docente": 3,
  "total": 2,
  "clases": [
    {
      "id_horario": 1,
      "estado_horario": "publicado",
      "nombre_carrera": "Ingeniería en Sistemas",
      "nombre_periodo": "Primer Semestre 2024",
      "nombre_curso": "Matemática I",
      "nombre_dia": "lunes",
      "hora_inicio": "18:00:00",
      "hora_fin": "19:30:00",
      "nombre_jornada": "vespertina"
    }
  ]
}
```

### Horario publicado para estudiante
**Request:** `GET /api/estudiante/horario?id_carrera=1&id_periodo_academico=1`  
**Response 200 — sin horario publicado:**
```json
{
  "publicado": false,
  "mensaje": "No existe horario publicado para la carrera y período indicados.",
  "detalles": []
}
```

### Notificaciones no leídas
**Response:**
```json
{
  "total": 2,
  "notificaciones": [
    {
      "id_notificacion": 15,
      "titulo": "Horario aprobado",
      "mensaje": "El horario de Ingeniería en Sistemas — Primer Semestre 2024 ha sido aprobado.",
      "tipo_notificacion": "aprobacion_horario",
      "fecha_envio": "2024-08-10 09:00:05"
    }
  ]
}
```

---

## Flujo general del sistema (resumen)

```
1. CONFIGURACIÓN INICIAL (admin)
   Usuarios → Roles → Facultades → Carreras → Coordinadores → Jornadas

2. CONFIGURACIÓN ACADÉMICA (admin/coord)
   PeriodoAcademico → Cursos → Pensum → PensumCurso (con ciclo_semestre)

3. INFRAESTRUCTURA DE BLOQUES (admin/coord)
   POST /bloques-horario/generar  (por carrera-jornada, día y rango horario)

4. ASIGNACIÓN DE DOCENTES (coord)
   Docentes → Secciones → POST /secciones/{id}/asignacion
   Validaciones: max_cursos_docente, no repetir ciclo por período

5. DISPONIBILIDAD DOCENTE (docente)
   POST /docentes/{id}/disponibilidad/toggle  (marcar bloqueos)

6. GENERACIÓN DE HORARIO (coord/sistema)
   GeneradorParcialService → PersistenciaHorarioService::confirmar()
   → Estado: borrador → generado

7. REVISIÓN Y CORRECCIÓN (coord)
   GET /horarios/{id}/completo
   PATCH /horarios/{id}/detalles/{det}/mover  (si hay conflictos residuales)

8. APROBACIÓN Y PUBLICACIÓN (admin)
   PATCH /horarios/{id}/aprobar   → generado → aprobado
   PATCH /horarios/{id}/publicar  → aprobado → publicado

9. CONSULTA (todos los roles)
   GET /horarios/{id}/completo
   GET /docente/horario
   GET /estudiante/horario?id_carrera=X&id_periodo_academico=Y
   GET /reportes/*
```

---

## Máquina de estados del horario

```
borrador ──[confirmar]──▶ generado ──[aprobar]──▶ aprobado
                                                      │
                                                 [bloquear]
                                                      │
                                                      ▼
                              publicado ◀──[publicar]── bloqueado
                                ▲
                           [publicar]
                                │
                           (desde aprobado también)
```

**Edición de contenido** (mover/eliminar clases): solo en `borrador` y `generado`.  
**Transiciones de estado**: solo administrador.  
**Estado terminal**: `publicado` — sin salida.
