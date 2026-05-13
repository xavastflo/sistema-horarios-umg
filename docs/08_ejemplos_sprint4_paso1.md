# Ejemplos JSON — Sprint 4, Paso 1: Consulta de Horarios

---

## 1. GET /api/horarios
**Acceso:** admin (todos), coordinador (sus carreras)

**Request:**
```
GET /api/horarios?id_periodo_academico=1&estado=generado
Authorization: Bearer {token}
```

**Response 200:**
```json
{
  "total": 2,
  "horarios": [
    {
      "id_horario": 1,
      "id_carrera": 1,
      "id_periodo_academico": 1,
      "version_horario": 1,
      "fecha_generacion": "2024-08-01 10:30:00",
      "fecha_aprobacion": null,
      "fecha_bloqueo": null,
      "observaciones": null,
      "nombre_carrera": "Ingeniería en Sistemas",
      "codigo_carrera": "INGSIST",
      "nombre_periodo": "Primer Semestre 2024",
      "anio": 2024,
      "numero_periodo": 1,
      "nombre_estado": "generado",
      "total_detalles": 12
    }
  ]
}
```

---

## 2. GET /api/horarios/por-carrera
**Acceso:** admin (toda carrera), coordinador (solo sus carreras)

**Request:**
```
GET /api/horarios/por-carrera?id_carrera=1&id_periodo_academico=1
Authorization: Bearer {token-coordinador}
```

**Response 200:**
```json
{
  "total": 1,
  "horarios": [
    {
      "id_horario": 1,
      "version_horario": 1,
      "nombre_carrera": "Ingeniería en Sistemas",
      "nombre_periodo": "Primer Semestre 2024",
      "nombre_estado": "aprobado",
      "total_detalles": 15
    }
  ]
}
```

**Response 403 — coordinador consultando carrera que no coordina:**
```json
{ "message": "No tiene permisos para consultar esta carrera." }
```

**Response 422 — parámetros faltantes:**
```json
{
  "message": "Error de validación.",
  "errors": {
    "id_carrera": ["The id carrera field is required."],
    "id_periodo_academico": ["The id periodo academico field is required."]
  }
}
```

---

## 3. GET /api/horarios/{id}/completo
**Acceso:** admin (cualquier horario), coordinador (solo sus carreras)

**Request:**
```
GET /api/horarios/1/completo
Authorization: Bearer {token}
```

**Response 200:**
```json
{
  "horario": {
    "id_horario": 1,
    "id_carrera": 1,
    "id_periodo_academico": 1,
    "version_horario": 1,
    "fecha_generacion": "2024-08-01 10:30:00",
    "fecha_aprobacion": "2024-08-05 09:00:00",
    "fecha_bloqueo": null,
    "observaciones": "Aprobado en reunión de facultad",
    "nombre_carrera": "Ingeniería en Sistemas",
    "codigo_carrera": "INGSIST",
    "nombre_periodo": "Primer Semestre 2024",
    "anio": 2024,
    "numero_periodo": 1,
    "nombre_estado": "aprobado"
  },
  "detalles": [
    {
      "id_detalle_horario": 1,
      "nombre_curso": "Matemática I",
      "codigo_curso": "MAT101",
      "numero_seccion": "A",
      "id_seccion": 1,
      "nombre_docente": "Juan Carlos Pérez Martínez",
      "codigo_docente": "DOC-001",
      "nombre_dia": "lunes",
      "orden_semana": 1,
      "hora_inicio": "18:00:00",
      "hora_fin": "19:30:00",
      "duracion_minutos": 90,
      "nombre_jornada": "vespertina",
      "ciclo_semestre": 1
    },
    {
      "id_detalle_horario": 2,
      "nombre_curso": "Programación I",
      "codigo_curso": "PRG101",
      "numero_seccion": "A",
      "id_seccion": 2,
      "nombre_docente": "María Elena González López",
      "codigo_docente": "DOC-002",
      "nombre_dia": "martes",
      "orden_semana": 2,
      "hora_inicio": "18:00:00",
      "hora_fin": "19:30:00",
      "duracion_minutos": 90,
      "nombre_jornada": "vespertina",
      "ciclo_semestre": 1
    },
    {
      "id_detalle_horario": 5,
      "nombre_curso": "Bases de Datos II",
      "codigo_curso": "BD201",
      "numero_seccion": "A",
      "id_seccion": 8,
      "nombre_docente": "Carlos Rodríguez Soto",
      "codigo_docente": "DOC-003",
      "nombre_dia": "miercoles",
      "orden_semana": 3,
      "hora_inicio": "19:30:00",
      "hora_fin": "21:00:00",
      "duracion_minutos": 90,
      "nombre_jornada": "vespertina",
      "ciclo_semestre": 5
    }
  ],
  "total": 3
}
```

> **Nota sobre `ciclo_semestre`:** resuelto via `Horario.id_carrera + Horario.id_periodo_academico → Pensum → Pensum_Curso`. Si el curso no está en el pensum de esta carrera/período, devuelve `null`.

**Response 403 — coordinador sin permiso sobre esa carrera:**
```json
{ "message": "No tiene permisos para consultar esta carrera." }
```

**Response 404:**
```json
{ "message": "Horario no encontrado." }
```

---

## 4. GET /api/horarios/mi-horario
**Acceso:** solo docente autenticado (sus propias clases)

**Request:**
```
GET /api/horarios/mi-horario
Authorization: Bearer {token-docente}
```

**Response 200:**
```json
{
  "id_docente": 3,
  "total": 3,
  "clases": [
    {
      "id_detalle_horario": 1,
      "id_horario": 1,
      "version_horario": 1,
      "estado_horario": "aprobado",
      "nombre_carrera": "Ingeniería en Sistemas",
      "codigo_carrera": "INGSIST",
      "nombre_periodo": "Primer Semestre 2024",
      "anio": 2024,
      "numero_periodo": 1,
      "nombre_curso": "Matemática I",
      "codigo_curso": "MAT101",
      "numero_seccion": "A",
      "nombre_dia": "lunes",
      "orden_semana": 1,
      "hora_inicio": "18:00:00",
      "hora_fin": "19:30:00",
      "duracion_minutos": 90,
      "nombre_jornada": "vespertina"
    },
    {
      "id_detalle_horario": 7,
      "id_horario": 2,
      "version_horario": 1,
      "estado_horario": "publicado",
      "nombre_carrera": "Administración de Empresas",
      "nombre_curso": "Estadística",
      "nombre_dia": "jueves",
      "hora_inicio": "18:00:00",
      "hora_fin": "19:30:00",
      "nombre_jornada": "vespertina"
    }
  ]
}
```

**Response 404 — usuario docente sin perfil docente:**
```json
{ "message": "Perfil docente no encontrado." }
```

---

## 5. GET /api/horarios/estudiante
**Acceso:** solo estudiante autenticado. Solo devuelve horarios `publicado`.

**Request:**
```
GET /api/horarios/estudiante?id_carrera=1&id_periodo_academico=1
Authorization: Bearer {token-estudiante}
```

**Response 200 — horario publicado encontrado:**
```json
{
  "publicado": true,
  "id_horario": 1,
  "total": 15,
  "detalles": [
    {
      "nombre_curso": "Matemática I",
      "codigo_curso": "MAT101",
      "numero_seccion": "A",
      "nombre_docente": "Juan Carlos Pérez Martínez",
      "nombre_dia": "lunes",
      "orden_semana": 1,
      "hora_inicio": "18:00:00",
      "hora_fin": "19:30:00",
      "duracion_minutos": 90,
      "nombre_jornada": "vespertina",
      "ciclo_semestre": 1
    },
    {
      "nombre_curso": "Programación I",
      "codigo_curso": "PRG101",
      "numero_seccion": "A",
      "nombre_docente": "María Elena González López",
      "nombre_dia": "martes",
      "orden_semana": 2,
      "hora_inicio": "18:00:00",
      "hora_fin": "19:30:00",
      "duracion_minutos": 90,
      "nombre_jornada": "vespertina",
      "ciclo_semestre": 1
    }
  ]
}
```

**Response 200 — sin horario publicado (no es 404):**
```json
{
  "publicado": false,
  "mensaje": "No existe horario publicado para la carrera y período indicados.",
  "detalles": []
}
```

**Response 422 — parámetros faltantes:**
```json
{
  "message": "Error de validación.",
  "errors": {
    "id_carrera": ["The id carrera field is required."]
  }
}
```
