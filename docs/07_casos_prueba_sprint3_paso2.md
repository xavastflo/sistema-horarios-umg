# Casos de Prueba — BloqueCandidatoService
## Sprint 3 — Paso 2: Selección de bloques candidatos

---

## Arquitectura del servicio

```
BloqueCandidatoService::obtenerCandidatos(
    idDocente, idSeccion, idHorario, idCarreraJornada, periodo, horario
)
│
├── validarFechaLimite()    → fallo global inmediato (0 queries)
├── validarEstadoHorario()  → fallo global inmediato (0 queries)
│
├── Query 1: cargarBloquesCarreraJornada()     → todos los bloques activos + JOIN dia
├── Query 2: cargarBloquesNoDisponiblesDocente() → IDs bloqueados por el docente
├── Query 3: cargarBloquesOcupadosDocente()    → IDs ocupados globalmente (mapa)
├── Query 4: cargarBloquesOcupadosEnHorario()  → IDs ocupados en este horario (mapa)
│
└── Por cada bloque candidato:
    ├── Filtro 2: lookup O(1) en set Query 2 → docenteNoDisponible
    ├── Filtro 3: lookup O(1) en mapa Query 3 → docenteOcupado
    ├── Filtro 4: lookup O(1) en mapa Query 4 → bloqueOcupadoEnHorario
    └── Filtro 5: ConflictValidationService::validarCicloTraslape() → 1 query/bloque

Retorno: BloqueCandidatoResultado {
    bloquesValidos:     Collection<BloqueHorario>,
    bloquesDescartados: Collection<BloqueDescartado>,
    contexto:           array
}
```

---

## Datos base para los casos de prueba

```
Carrera: Ingeniería en Sistemas (id=1)
Jornada: vespertina (id=2)
CarreraJornada: id=1 (carrera 1 + jornada 2)
Período: PA1 (id=1, fecha_limite: null, estado: activo)
Horario: H1 (id=1, carrera=1, período=PA1, estado: 'borrador')

Bloques en la carrera-jornada 1:
  B1: id=1, lunes 18:00-19:30
  B2: id=2, lunes 19:30-21:00
  B3: id=3, martes 18:00-19:30
  B4: id=4, martes 19:30-21:00
  B5: id=5, miercoles 18:00-19:30

Docente: D1 (id=3, prioridad=1)
Sección: SEC1 (id=1, curso: Matemática I, ciclo=3)
Sección: SEC2 (id=2, curso: Programación I, ciclo=3)
Sección: SEC3 (id=3, curso: Bases de Datos, ciclo=5)
```

---

## GRUPO 1 — Fallos globales (retorno vacío inmediato)

### CT-BC-001 ❌ Fecha límite vencida → sin evaluación de bloques
```
Setup: período con fecha_limite_edicion_horarios = "2024-01-01 00:00:00" (pasada)

Llamada: obtenerCandidatos(D1, SEC1, H1, CJ1, periodo, horario)

Resultado esperado:
  bloquesValidos.count():     0
  bloquesDescartados.count(): 0   ← no se evaluó ningún bloque
  contexto.motivo_global: "fecha_limite_vencida"
  Queries ejecutadas: 0
```

### CT-BC-002 ❌ Horario bloqueado → sin evaluación de bloques
```
Setup: H1.estado = 'bloqueado'

Resultado esperado:
  bloquesValidos.count():     0
  bloquesDescartados.count(): 0
  contexto.motivo_global: "horario_no_editable"
  Queries ejecutadas: 0
```

### CT-BC-003 ❌ Sin bloques definidos en la carrera-jornada
```
Setup: La carrera-jornada no tiene bloques activos en bloque_horario.

Resultado esperado:
  bloquesValidos.count():     0
  bloquesDescartados.count(): 0
  contexto.motivo_global: "sin_bloques_definidos"
  Queries ejecutadas: 1 (Query 1 devuelve vacío)
```

---

## GRUPO 2 — Filtro por disponibilidad docente

### CT-BC-004 ✅ Docente sin restricciones → todos los bloques válidos
```
Setup:
  5 bloques activos en CJ1
  0 registros en disponibilidad_docente para D1
  0 detalles en H1
  D1 sin clases en otro horario

Resultado esperado:
  bloquesValidos.count():     5
  bloquesDescartados.count(): 0
  Queries ejecutadas: 4 (precarga) + 5 (ciclo traslape, uno por bloque)
```

### CT-BC-005 ❌ Docente bloqueó B1 → B1 descartado
```
Setup:
  disponibilidad_docente: { id_docente: D1, id_bloque_horario: B1, estado: 'activo' }

Resultado esperado:
  bloquesValidos: [B2, B3, B4, B5]
  bloquesDescartados: [
    { id_bloque: B1, motivos: [{ tipo: "docente_no_disponible", ... }] }
  ]
```

### CT-BC-006 ✅ Registro de disponibilidad inactivo → no filtra
```
Setup:
  disponibilidad_docente: { id_docente: D1, id_bloque: B1, estado: 'inactivo' }

Resultado esperado:
  B1 NO es descartado por este motivo
  (el docente lo desmarcó — ahora está disponible)
```

### CT-BC-007 ❌ Docente bloqueó múltiples bloques
```
Setup:
  disponibilidad_docente: { D1, B1, activo }, { D1, B3, activo }

Resultado esperado:
  bloquesValidos: [B2, B4, B5]
  bloquesDescartados: [B1, B3] con tipo "docente_no_disponible"
```

---

## GRUPO 3 — Filtro por docente ocupado globalmente

### CT-BC-008 ❌ Docente ya tiene clase en B2 en OTRA carrera
```
Setup:
  Horario H2 (carrera: Administración, estado: 'generado')
  detalle_horario: { id_horario: H2, id_bloque: B2, docente: D1, estado: 'activo' }

Llamada: obtenerCandidatos(..., idHorario=H1, ...)

Resultado esperado:
  B2 descartado con tipo "docente_ocupado"
  contexto del descarte incluye: id_horario_conflicto=H2, carrera="Administración"
  bloquesValidos: [B1, B3, B4, B5]  ← si no hay otras restricciones
```

### CT-BC-009 ✅ Docente tiene clase en B3 en el MISMO horario → no filtra en Query 3
```
Setup:
  detalle_horario: { id_horario: H1, id_bloque: B3, docente: D1 }

Query 3 excluye idHorarioActual=H1 → B3 NO aparece en bloquesOcupadosDocente

Resultado: B3 llega hasta el Filtro 4 (será descartado allí, no en Filtro 3)

Explicación: Si el docente ya tiene otra sección en B3 dentro del mismo
             horario H1, eso se captura por Filtro 4 (bloque ocupado en horario),
             no por Filtro 3 (ocupado globalmente). Esto permite que la
             edición manual pueda reubicar clases dentro del mismo horario.
```

### CT-BC-010 ❌ Docente con clase en horario con estado 'bloqueado' → no genera conflicto
```
Setup:
  Horario H_viejo (estado: 'bloqueado')
  detalle_horario: { id_horario: H_viejo, id_bloque: B1, docente: D1 }

Resultado esperado:
  B1 NO es descartado por Filtro 3
  Explicación: La Query 3 solo considera horarios en estado
               borrador, generado, aprobado, publicado.
               Un horario bloqueado está fuera del ciclo activo.
```

---

## GRUPO 4 — Filtro por bloque ocupado en el horario actual

### CT-BC-011 ❌ B3 ya tiene otra sección en H1
```
Setup:
  detalle_horario: { id_horario: H1, id_bloque: B3, seccion: SEC2, estado: 'activo' }

Resultado esperado:
  B3 descartado con tipo "bloque_ocupado_en_horario"
  contexto incluye: seccion_descripcion="Programación I — Sec. A"
  bloquesValidos: [B1, B2, B4, B5]
```

### CT-BC-012 ✅ Mismo bloque en otro horario → no filtra
```
Setup:
  detalle_horario: { id_horario: H2, id_bloque: B3, estado: 'activo' }

Llamada: obtenerCandidatos(..., idHorario=H1, ...)

Resultado esperado:
  B3 NO filtrado por Filtro 4
  (cada horario es independiente)
```

### CT-BC-013 ❌ Bloque con detalle inactivo en H1 → no filtra
```
Setup:
  detalle_horario: { id_horario: H1, id_bloque: B3, estado: 'inactivo' }

Resultado esperado:
  B3 NO filtrado
  (los detalles inactivos no bloquean)
```

---

## GRUPO 5 — Filtro por traslape de ciclo

### CT-BC-014 ❌ B1 ya tiene un curso del mismo ciclo en H1
```
Setup:
  H1 tiene en B1: SEC2 (Programación I, ciclo=3)
  Queremos asignar: SEC1 (Matemática I, ciclo=3)

Filtros 2, 3, 4: B1 pasa todos (docente D1 libre en otros aspectos)
Filtro 5 (ciclo traslape): B1 bloqueado ← ciclo 3 ya está en B1

Resultado esperado:
  B1 descartado con tipo "ciclo_traslape"
  contexto: { ciclo_semestre: 3, curso_conflicto: "Programación I" }
```

### CT-BC-015 ✅ Mismo bloque pero diferente ciclo → permitido
```
Setup:
  H1 tiene en B1: SEC2 (Programación I, ciclo=3)
  Queremos asignar: SEC3 (Bases de Datos, ciclo=5)

Filtro 5: ciclo 3 ≠ ciclo 5 → sin traslape

Resultado esperado:
  B1 incluido en bloquesValidos
```

### CT-BC-016 ✅ Curso sin pensum en el período → Filtro 5 no bloquea
```
Setup:
  SEC_SIN_PENSUM: curso que no tiene registro en pensum_curso del período PA1

Resultado esperado:
  El bloque candidato pasa el Filtro 5 sin ser rechazado
  Explicación: Sin ciclo_semestre definido no se puede validar traslape.
               Decisión conservadora: no bloquear lo no verificable.
```

---

## GRUPO 6 — Acumulación de múltiples motivos de descarte

### CT-BC-017 Cada bloque descartado por motivo diferente
```
Setup:
  B1: disponibilidad_docente { D1, B1, activo }      ← Filtro 2
  B2: detalle_horario H2 { D1, B2, activo }           ← Filtro 3 (otra carrera)
  B3: detalle_horario H1 { SEC2, B3, activo }         ← Filtro 4 (este horario)
  B4: H1 tiene SEC2 en B4 del mismo ciclo             ← Filtro 5
  B5: sin ninguna restricción                         ← VÁLIDO

Resultado esperado:
  bloquesValidos:     [B5]
  bloquesDescartados: [
    { B1, tipo: "docente_no_disponible" },
    { B2, tipo: "docente_ocupado" },
    { B3, tipo: "bloque_ocupado_en_horario" },
    { B4, tipo: "ciclo_traslape" },
  ]
```

### CT-BC-018 Todos los bloques descartados → resultado vacío
```
Setup:
  Todos los bloques tienen alguna restricción activa.

Resultado esperado:
  bloquesValidos.count():     0
  bloquesDescartados.count(): 5 (uno por bloque)
  tieneBloquesValidos():      false

Uso en el algoritmo:
  if (! $resultado->tieneBloquesValidos()) {
      // Marcar sección como NO asignable → revisión manual del coordinador
  }
```

---

## GRUPO 7 — Estructura del retorno (serialización)

### CT-BC-019 toArray() estructura correcta
```
Llamada: $resultado->toArray()

Estructura esperada:
{
  "total_validos": 3,
  "total_descartados": 2,
  "contexto": {
    "id_docente": 3,
    "id_seccion": 1,
    "id_horario": 1,
    "id_carrera_jornada": 1,
    "id_periodo": 1,
    "total_evaluados": 5
  },
  "bloques_validos": [
    {
      "id_bloque_horario": 3,
      "id_dia": 2,
      "hora_inicio": "18:00:00",
      "hora_fin": "19:30:00",
      "duracion_minutos": 90
    },
    ...
  ],
  "bloques_descartados": [
    {
      "id_bloque_horario": 1,
      "id_dia": 1,
      "hora_inicio": "18:00:00",
      "hora_fin": "19:30:00",
      "motivos": [
        {
          "tipo": "docente_no_disponible",
          "mensaje": "El docente marcó el bloque lunes 18:00-19:30 como no disponible.",
          "contexto": { "id_docente": 3, "dia": "lunes", ... }
        }
      ]
    },
    ...
  ]
}
```

---

## GRUPO 8 — Eficiencia de queries

### CT-BC-020 Conteo de queries para N bloques
```
Escenario: 10 bloques activos en la carrera-jornada,
           8 pasan los filtros 2-4, 2 se descartan antes del Filtro 5.

Queries esperadas:
  4  fijas de precarga (Queries 1-4)
  8  por Filtro 5 (uno por cada bloque que llega hasta el cicloTraslape)
  ─────────────────────────────────
  12 total

Sin la estrategia de precarga (naive):
  10 × 4 validaciones = 40 queries

Mejora: ~70% menos queries en este escenario
```

### CT-BC-021 Escenario ideal: todos los bloques fallan antes del Filtro 5
```
Setup:
  Todos los bloques bloqueados por disponibilidad (Filtro 2)

Queries esperadas:
  4 fijas (precarga) + 0 de cicloTraslape
  ────────────────────────────────────────
  4 total

Mejor caso posible del servicio.
```

---

## Orden de ejecución recomendado

```
CT-BC-001 al CT-BC-003   (fallos globales — sin BD necesaria)
CT-BC-004                (caso limpio base)
CT-BC-005 al CT-BC-007   (filtros de disponibilidad)
CT-BC-008 al CT-BC-010   (filtros de docente ocupado)
CT-BC-011 al CT-BC-013   (filtros de bloque en horario)
CT-BC-014 al CT-BC-016   (filtros de ciclo)
CT-BC-017 al CT-BC-018   (múltiples restricciones)
CT-BC-019                (serialización)
CT-BC-020 al CT-BC-021   (eficiencia)
```
