<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  body    { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
  h1      { font-size: 14px; margin-bottom: 2px; }
  h2      { font-size: 12px; margin: 14px 0 4px; border-bottom: 1px solid #1a3a5c; padding-bottom: 2px; }
  .meta   { font-size: 9px; color: #777; margin-bottom: 10px; }
  table   { width: 100%; border-collapse: collapse; margin-top: 6px; }
  th      { background: #1a3a5c; color: #fff; padding: 5px 4px; text-align: left; font-size: 9px; }
  td      { padding: 4px; border-bottom: 1px solid #ddd; }
  tr:nth-child(even) td { background: #f5f8fc; }
  .badge-sd  { background: #f8d7da; color: #721c24; padding: 1px 5px; border-radius: 3px; font-size: 8px; }
  .badge-sbh { background: #fff3cd; color: #856404; padding: 1px 5px; border-radius: 3px; font-size: 8px; }
</style>
</head>
<body>

<h1>Secciones no asignadas</h1>
<p class="meta">
  Sin docente: {{ $datos['total_sin_docente'] }}
  &nbsp;|&nbsp;
  Sin bloque en horario: {{ $datos['total_sin_bloque'] }}
</p>

<h2>Sin docente asignado <span class="badge-sd">SIN_DOCENTE</span></h2>
<table>
  <thead>
    <tr><th>Curso</th><th>Cód.</th><th>Sección</th><th>Ciclo</th><th>Motivo</th></tr>
  </thead>
  <tbody>
    @forelse($datos['sin_docente'] as $r)
    <tr>
      <td>{{ $r->nombre_curso }}</td>
      <td>{{ $r->codigo_curso }}</td>
      <td>{{ $r->numero_seccion }}</td>
      <td>{{ $r->ciclo_semestre ?? '—' }}</td>
      <td>{{ $r->motivo }}</td>
    </tr>
    @empty
    <tr><td colspan="5" style="text-align:center;color:#999">Ninguna sección sin docente.</td></tr>
    @endforelse
  </tbody>
</table>

<h2>Con docente pero sin bloque en el horario <span class="badge-sbh">SIN_BLOQUE_EN_HORARIO</span></h2>
<table>
  <thead>
    <tr><th>Curso</th><th>Cód.</th><th>Sección</th><th>Ciclo</th><th>Motivo</th></tr>
  </thead>
  <tbody>
    @forelse($datos['sin_bloque_en_horario'] as $r)
    <tr>
      <td>{{ $r->nombre_curso }}</td>
      <td>{{ $r->codigo_curso }}</td>
      <td>{{ $r->numero_seccion }}</td>
      <td>{{ $r->ciclo_semestre ?? '—' }}</td>
      <td>{{ $r->motivo }}</td>
    </tr>
    @empty
    <tr><td colspan="5" style="text-align:center;color:#999">Todas las secciones con docente tienen bloque asignado.</td></tr>
    @endforelse
  </tbody>
</table>

</body>
</html>
