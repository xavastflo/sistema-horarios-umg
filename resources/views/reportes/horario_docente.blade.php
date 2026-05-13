<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  body  { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
  h1    { font-size: 14px; margin-bottom: 2px; }
  h3    { font-size: 11px; margin: 2px 0; font-weight: normal; color: #555; }
  .meta { font-size: 9px; color: #777; margin-bottom: 10px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th    { background: #1a3a5c; color: #fff; padding: 5px 4px; text-align: left; font-size: 9px; }
  td    { padding: 4px; border-bottom: 1px solid #ddd; vertical-align: top; }
  tr:nth-child(even) td { background: #f5f8fc; }
</style>
</head>
<body>

<h1>Horario del docente</h1>
<h3>{{ $nombreDocente }}</h3>
<p class="meta">Total clases: {{ count($clases) }}</p>

<table>
  <thead>
    <tr>
      <th>Carrera</th>
      <th>Período</th>
      <th>Estado</th>
      <th>Curso</th>
      <th>Sección</th>
      <th>Ciclo</th>
      <th>Día</th>
      <th>Hora inicio</th>
      <th>Hora fin</th>
      <th>Jornada</th>
    </tr>
  </thead>
  <tbody>
    @forelse($clases as $d)
    <tr>
      <td>{{ $d->nombre_carrera }}</td>
      <td>{{ $d->nombre_periodo }}</td>
      <td>{{ ucfirst($d->estado_horario) }}</td>
      <td>{{ $d->nombre_curso }}</td>
      <td>{{ $d->numero_seccion }}</td>
      <td>{{ $d->ciclo_semestre ?? '—' }}</td>
      <td>{{ ucfirst($d->nombre_dia) }}</td>
      <td>{{ substr($d->hora_inicio, 0, 5) }}</td>
      <td>{{ substr($d->hora_fin, 0, 5) }}</td>
      <td>{{ ucfirst($d->nombre_jornada) }}</td>
    </tr>
    @empty
    <tr><td colspan="10" style="text-align:center;color:#999">Sin clases registradas.</td></tr>
    @endforelse
  </tbody>
</table>

</body>
</html>
