<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  body      { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
  h1        { font-size: 14px; margin-bottom: 2px; }
  h3        { font-size: 11px; margin: 2px 0; font-weight: normal; color: #555; }
  .meta     { font-size: 9px; color: #777; margin-bottom: 10px; }
  table     { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th        { background: #1a3a5c; color: #fff; padding: 5px 4px; text-align: left; font-size: 9px; }
  td        { padding: 4px; border-bottom: 1px solid #ddd; vertical-align: top; }
  tr:nth-child(even) td { background: #f5f8fc; }
  .ciclo    { display: inline-block; background: #e8eef5; border-radius: 3px;
              padding: 1px 4px; font-size: 8px; }
</style>
</head>
<body>

<h1>Horario de clases — {{ $horario->nombre_carrera }}</h1>
<h3>{{ $horario->nombre_periodo }} ({{ $horario->anio }})</h3>
<p class="meta">
  Estado: <strong>{{ ucfirst($horario->nombre_estado) }}</strong>
  &nbsp;|&nbsp; Generado: {{ $horario->fecha_generacion ?? '—' }}
  &nbsp;|&nbsp; Total clases: {{ count($detalles) }}
</p>

<table>
  <thead>
    <tr>
      <th>Curso</th>
      <th>Sección</th>
      <th>Ciclo</th>
      <th>Docente</th>
      <th>Día</th>
      <th>Hora inicio</th>
      <th>Hora fin</th>
      <th>Jornada</th>
    </tr>
  </thead>
  <tbody>
    @forelse($detalles as $d)
    <tr>
      <td>{{ $d->nombre_curso }} <br><small>{{ $d->codigo_curso }}</small></td>
      <td>{{ $d->numero_seccion }}</td>
      <td><span class="ciclo">{{ $d->ciclo_semestre ?? '—' }}</span></td>
      <td>{{ $d->nombre_docente }} <br><small>{{ $d->codigo_docente }}</small></td>
      <td>{{ ucfirst($d->nombre_dia) }}</td>
      <td>{{ substr($d->hora_inicio, 0, 5) }}</td>
      <td>{{ substr($d->hora_fin, 0, 5) }}</td>
      <td>{{ ucfirst($d->nombre_jornada) }}</td>
    </tr>
    @empty
    <tr><td colspan="8" style="text-align:center;color:#999">Sin detalles registrados.</td></tr>
    @endforelse
  </tbody>
</table>

</body>
</html>
