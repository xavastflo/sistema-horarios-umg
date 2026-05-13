# Casos de Prueba — ConflictValidationService
## Sprint 3 — Paso 1: Validación de conflictos de horario

---

## Arquitectura del servicio

```
ConflictValidationService
├── validarTodo()                  ← usado por el algoritmo
├── validarParaEdicionManual()     ← usado por modificación manual
│
├── validarFechaLimite()           → ValidacionResultado
├── validarEstadoHorario()         → ValidacionResultado
├── validarDisponibilidadDocente() → ValidacionResultado
├── validarDocenteOcupado()        → ValidacionResultado
├── validarCicloTraslape()         → ValidacionResultado
└── validarBloqueEnHorario()       → ValidacionResultado

DTOs:
├── ValidacionResultado  { esValido(), conflictos[], toArray() }
└── ConflictoItem        { tipo, mensaje, contexto }
```

---

## GRUPO 1 — validarFechaLimite()

**Firma real:** `validarFechaLimite(PeriodoAcademico $periodo): ValidacionResultado`

El método **no recibe un id** sino el modelo `PeriodoAcademico` ya hidratado.
El llamador (algoritmo o controller) carga el período una sola vez y lo pasa
a todos los métodos que lo necesitan. El acceso a `fecha_limite_edicion_horarios`
es en memoria — **cero queries adicionales** en esta validación. Esto es
correcto tal como está implementado.

### CT-FL-001 ✅ Sin fecha límite → siempre permitido
```
Periodo: { fecha_limite_edicion_horarios: null }

Resultado esperado:
  esValido: true
  conflictos: []

Explicación: Null = sin límite configurado. El período siempre es editable.
```

### CT-FL-002 ✅ Fecha límite futura → permitido
```
Periodo: { fecha_limite_edicion_horarios: "2024-12-31 23:59:59" }
Momento actual: 2024-06-15 10:00:00

Resultado esperado:
  esValido: true
  conflictos: []
```

### CT-FL-003 ❌ Fecha límite vencida → rechazado
```
Periodo: { fecha_limite_edicion_horarios: "2024-01-10 23:59:59" }
Momento actual: 2024-06-15 10:00:00

Resultado esperado:
  esValido: false
  conflictos: [{
    tipo: "fecha_limite_vencida",
    mensaje: "El período académico superó la fecha límite...",
    contexto: { fecha_limite: "2024-01-10 23:59:59" }
  }]

Comportamiento crítico:
  validarTodo() se detiene aquí — NO ejecuta las validaciones 3-6.
```

### CT-FL-004 ⚠️ Fecha límite exactamente ahora → borde
```
Periodo: { fecha_limite_edicion_horarios: now() }

Resultado esperado:
  esValido: true   ← lessThanOrEqualTo incluye el instante exacto
```

---

## GRUPO 2 — validarEstadoHorario()

**Alcance:** Valida si el horario permite editar su **contenido** (clases asignadas).
No controla transiciones administrativas de estado (aprobar, bloquear, publicar).

**Separación de responsabilidades:**
| Operación | ¿Pasa por este servicio? | Responsable |
|---|---|---|
| Mover una clase de bloque (coordinador) | ✅ Sí | `ConflictValidationService` |
| Algoritmo escribe un `detalle_horario` | ✅ Sí | `ConflictValidationService` |
| Admin aprueba el horario | ❌ No | `HorarioStateService` (Paso 6) |
| Admin bloquea el horario | ❌ No | `HorarioStateService` (Paso 6) |
| Admin publica el horario | ❌ No | `HorarioStateService` (Paso 6) |

Los estados `aprobado`, `bloqueado` y `publicado` bloquean la edición de contenido
porque el coordinador no debe poder modificar un horario ya aprobado o publicado.
El administrador sí puede ejecutar transiciones de estado, pero eso es una operación
diferente con un servicio diferente.

### CT-EH-001 ✅ Horario en borrador → editable
```
Horario: { id_estado_horario: 1 }  (borrador)

Resultado esperado:
  esValido: true
```

### CT-EH-002 ✅ Horario en generado → editable
```
Horario: { id_estado_horario: 2 }  (generado)

Resultado esperado:
  esValido: true
```

### CT-EH-003 ❌ Horario aprobado → bloqueado
```
Horario: { id_estado_horario: 3 }  (aprobado)

Resultado esperado:
  esValido: false
  conflictos: [{
    tipo: "horario_no_editable",
    mensaje: "El horario está en estado 'aprobado' y no puede modificarse.",
    contexto: { estado_horario: "aprobado" }
  }]

Comportamiento crítico:
  validarTodo() se detiene aquí — NO ejecuta las validaciones 3-6.
```

### CT-EH-004 ❌ Horario bloqueado → rechazado
```
Horario: { id_estado_horario: 4 }  (bloqueado)

Resultado esperado:
  esValido: false
  conflictos[0].tipo: "horario_no_editable"
```

### CT-EH-005 ❌ Horario publicado → rechazado
```
Horario: { id_estado_horario: 5 }  (publicado)

Resultado esperado:
  esValido: false
  conflictos[0].tipo: "horario_no_editable"
```

---

## GRUPO 3 — validarDisponibilidadDocente()

### CT-DD-001 ✅ Sin registro de disponibilidad → disponible
```
Estado BD: No existe ningún registro activo en disponibilidad_docente
  para (id_docente=3, id_bloque_horario=5)

Resultado esperado:
  esValido: true
  conflictos: []

Explicación: Ausencia de registro = disponible (regla del sistema).
```

### CT-DD-002 ❌ Registro activo en disponibilidad_docente → no disponible
```
Estado BD:
  disponibilidad_docente {
    id_docente: 3,
    id_bloque_horario: 5,   (lunes 18:00-19:30)
    estado: 'activo'
  }

Resultado esperado:
  esValido: false
  conflictos: [{
    tipo: "docente_no_disponible",
    mensaje: "El docente marcó el bloque lunes 18:00-19:30 como no disponible.",
    contexto: {
      id_docente: 3,
      id_bloque_horario: 5,
      dia: "lunes",
      hora_inicio: "18:00:00",
      hora_fin: "19:30:00"
    }
  }]
```

### CT-DD-003 ✅ Registro inactivo → no bloquea
```
Estado BD:
  disponibilidad_docente {
    id_docente: 3,
    id_bloque_horario: 5,
    estado: 'inactivo'   ← desmarcado por el docente
  }

Resultado esperado:
  esValido: true   ← solo registros 'activo' bloquean
```

### CT-DD-004 ✅ Registro de otro docente → no afecta
```
Estado BD:
  disponibilidad_docente { id_docente: 99, id_bloque_horario: 5, estado: 'activo' }

Consulta: validarDisponibilidadDocente(idDocente=3, idBloque=5)

Resultado esperado:
  esValido: true   ← la restricción es por docente específico
```

---

## GRUPO 4 — validarDocenteOcupado() — chequeo GLOBAL

### CT-DO-001 ✅ Docente sin clases en ese bloque → libre
```
Estado BD: No existe detalle_horario activo con
  id_docente=3 y id_bloque_horario=5

Resultado esperado:
  esValido: true
```

### CT-DO-002 ❌ Docente con clase en otro horario mismo bloque
```
Estado BD:
  horario H1 (carrera A, estado: generado)
  detalle_horario { id_horario: H1, id_bloque: 5, id_asignacion: X, estado: activo }
  asignacion_docente_curso { id_asignacion: X, id_docente: 3, estado: activo }

Consulta: validarDocenteOcupado(idDocente=3, idBloque=5, idHorarioActual=H2)

Resultado esperado:
  esValido: false
  conflictos: [{
    tipo: "docente_ocupado",
    mensaje: "El docente ya tiene clase el lunes 18:00-19:30 (en carrera: Ingeniería en Sistemas).",
    contexto: {
      id_docente: 3,
      id_horario_conflicto: H1,
      carrera_conflicto: "Ingeniería en Sistemas"
    }
  }]

CRÍTICO: El conflicto ocurre aunque H1 y H2 sean de carreras distintas.
```

### CT-DO-003 ✅ Misma asignación en el mismo horario → excluida
```
Estado BD:
  detalle_horario { id_horario: H1, id_bloque: 5, docente: 3, estado: activo }

Consulta: validarDocenteOcupado(idDocente=3, idBloque=5, idHorarioActual=H1)

Resultado esperado:
  esValido: true
  Explicación: Se excluye el horario actual para permitir validar
               sin auto-conflictar al reubicar dentro del mismo horario.
```

### CT-DO-004 ✅ Horario bloqueado no genera conflicto de docente
```
Estado BD:
  horario H_bloqueado (estado: 'bloqueado')
  detalle_horario { id_horario: H_bloqueado, id_bloque: 5, docente: 3 }

Consulta: validarDocenteOcupado(idDocente=3, idBloque=5, idHorarioActual=H_nuevo)

Resultado esperado:
  esValido: true
  Explicación: Los horarios en estado 'bloqueado' no generan conflicto
               porque están desactivados del ciclo activo.
               (El WHERE solo incluye borrador, generado, aprobado, publicado)
```

---

## GRUPO 5 — validarCicloTraslape()

### CT-CT-001 ✅ Bloque sin otros cursos del mismo ciclo → libre
```
Estado BD:
  En el bloque 5 del horario H1 no hay ninguna clase del ciclo 3.

Consulta: validarCicloTraslape(idSeccion=S_nueva_ciclo3, idBloque=5, idHorario=H1, idPeriodo=P1)

Resultado esperado:
  esValido: true
```

### CT-CT-002 ❌ Bloque con otro curso del mismo ciclo → conflicto
```
Estado BD:
  detalle_horario { id_horario: H1, id_bloque: 5, asignacion: (Matemática I, ciclo 3) }

Consulta: validarCicloTraslape(
  idSeccion = Sección de "Programación I" (también ciclo 3),
  idBloque  = 5,
  idHorario = H1,
  idPeriodo = P1
)

Resultado esperado:
  esValido: false
  conflictos: [{
    tipo: "ciclo_traslape",
    mensaje: "Ya existe una clase del ciclo 3 el lunes 18:00-19:30 (curso: Matemática I).",
    contexto: {
      ciclo_semestre: 3,
      curso_conflicto: "Matemática I"
    }
  }]
```

### CT-CT-003 ✅ Cursos de ciclos distintos en el mismo bloque → permitido
```
Estado BD:
  detalle_horario { id_horario: H1, id_bloque: 5, asignacion: (Matemática I, ciclo 1) }

Consulta: validarCicloTraslape(
  idSeccion = Sección de "Bases de Datos" (ciclo 5),
  idBloque  = 5,
  idHorario = H1
)

Resultado esperado:
  esValido: true   ← ciclo 1 ≠ ciclo 5, no hay traslape
```

### CT-CT-004 ✅ Curso sin pensum en el período → no bloquea
```
Estado BD: El curso de la sección no existe en ningún pensum_curso
  del período P1.

Resultado esperado:
  esValido: true
  Explicación: Sin ciclo_semestre definido no se puede validar traslape.
               La decisión es permisiva: no bloquear lo que no se puede verificar.
```

### CT-CT-005 ✅ La misma sección no conflicta consigo misma
```
Escenario: Reubicando la sección S1 de bloque 5 a bloque 7.
  detalle_horario ya tiene { id_horario: H1, id_bloque: 5, asignacion: S1 }

Consulta: validarCicloTraslape(idSeccion=S1, idBloque=5, idHorario=H1, ...)

Resultado esperado:
  esValido: true
  Explicación: La cláusula WHERE s.id_seccion != idSeccion excluye
               la propia sección para evitar auto-conflicto.
```

---

## GRUPO 6 — validarBloqueEnHorario()

### CT-BH-001 ✅ Bloque libre en el horario → asignable
```
Estado BD: No existe detalle_horario activo con id_horario=H1, id_bloque=5

Resultado esperado:
  esValido: true
```

### CT-BH-002 ❌ Bloque ya ocupado en el mismo horario → rechazado
```
Estado BD:
  detalle_horario { id_horario: H1, id_bloque: 5, estado: 'activo',
    asignacion → Sección "A" de "Matemática I" }

Consulta: validarBloqueEnHorario(idBloque=5, idHorario=H1)

Resultado esperado:
  esValido: false
  conflictos: [{
    tipo: "bloque_ocupado_en_horario",
    mensaje: "El bloque lunes 18:00-19:30 ya está asignado en este horario (sección: Matemática I — Sec. A).",
    contexto: { ... }
  }]
```

### CT-BH-003 ✅ Mismo bloque en otro horario → no conflicta
```
Estado BD:
  detalle_horario { id_horario: H2, id_bloque: 5, estado: 'activo' }

Consulta: validarBloqueEnHorario(idBloque=5, idHorario=H1)

Resultado esperado:
  esValido: true   ← cada horario es independiente
```

### CT-BH-004 ✅ Con excluirDetalle → permite reubicar
```
Estado BD:
  detalle_horario { id_detalle_horario: 42, id_horario: H1, id_bloque: 5, estado: activo }

Consulta: validarBloqueEnHorario(idBloque=5, idHorario=H1, excluirDetalle=42)

Resultado esperado:
  esValido: true
  Explicación: Al reubicar la clase 42 dentro del mismo bloque, se excluye
               su propio registro para no auto-conflictar.
```

---

## GRUPO 7 — validarTodo() — comportamiento de fallo rápido

### CT-VT-001 Fallo rápido en fecha límite — no ejecuta queries
```
Setup: Periodo con fecha_limite vencida.

Llamada: validarTodo(...)

Resultado esperado:
  Se devuelve inmediatamente con ConflictoItem(FECHA_LIMITE_VENCIDA).
  Las validaciones 3-6 (que requieren queries) NO se ejecutan.
  Queries totales ejecutadas: 0
```

### CT-VT-002 Fallo rápido en estado horario
```
Setup: Periodo OK, Horario en estado 'bloqueado'.

Llamada: validarTodo(...)

Resultado esperado:
  Se devuelve inmediatamente con ConflictoItem(HORARIO_NO_EDITABLE).
  Las validaciones 3-6 NO se ejecutan.
  Queries totales: 1 (estado del horario)
```

### CT-VT-003 Múltiples conflictos de datos — todos reportados
```
Setup:
  - Fecha límite OK
  - Estado borrador OK
  - Docente marcó el bloque como no disponible
  - Docente ya tiene clase en ese bloque en otra carrera
  - Hay otro curso del mismo ciclo en ese bloque

Llamada: validarTodo(...)

Resultado esperado:
  esValido: false
  conflictos: [
    { tipo: "docente_no_disponible", ... },
    { tipo: "docente_ocupado", ... },
    { tipo: "ciclo_traslape", ... }
  ]
  Explicación: Las validaciones 3-6 se ejecutan TODAS y se acumulan.
               El coordinador recibe información completa.
```

### CT-VT-004 ✅ Sin ningún conflicto
```
Setup: Todo limpio (docente disponible, bloque libre, ciclo libre).

Resultado esperado:
  esValido: true
  conflictos: []
  Queries ejecutadas: 4 (validaciones 3, 4, 5, 6)
```

---

## Respuesta API esperada (toArray)

### Caso sin conflictos
```json
{
  "es_valido": true,
  "conflictos": []
}
```

### Caso con múltiples conflictos
```json
{
  "es_valido": false,
  "conflictos": [
    {
      "tipo": "docente_no_disponible",
      "mensaje": "El docente marcó el bloque lunes 18:00-19:30 como no disponible.",
      "contexto": {
        "id_docente": 3,
        "id_bloque_horario": 5,
        "dia": "lunes",
        "hora_inicio": "18:00:00",
        "hora_fin": "19:30:00"
      }
    },
    {
      "tipo": "ciclo_traslape",
      "mensaje": "Ya existe una clase del ciclo 3 el lunes 18:00-19:30 (curso: Matemática I).",
      "contexto": {
        "ciclo_semestre": 3,
        "id_bloque_horario": 5,
        "dia": "lunes",
        "hora_inicio": "18:00:00",
        "hora_fin": "19:30:00",
        "curso_conflicto": "Matemática I"
      }
    }
  ]
}
```

---

## Orden de ejecución recomendado

```
CT-FL-001 → CT-FL-004   (sin BD)
CT-EH-001 → CT-EH-005   (sin BD, con modelo Horario mock)
CT-DD-001 → CT-DD-004   (requiere disponibilidad_docente en BD)
CT-DO-001 → CT-DO-004   (requiere detalle_horario en BD)
CT-CT-001 → CT-CT-005   (requiere pensum_curso + detalle_horario)
CT-BH-001 → CT-BH-004   (requiere detalle_horario)
CT-VT-001 → CT-VT-004   (pruebas de integración del servicio completo)
```
