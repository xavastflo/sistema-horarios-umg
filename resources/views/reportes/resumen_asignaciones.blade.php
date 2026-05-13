<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  body  { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
  h1    { font-size: 14px; margin-bottom: 2px; }
  .meta { font-size: 9px; color: #777; margin-bottom: 10px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th    { background: #1a3a5c; color: #fff; padding: 5px 4px; text-align: left; font-size: 9px; }
  td    { padding: 4px; border-bottom: 1px solid #ddd; }
  tr:nth-child(even) td { background: #f5f8fc; }
  .num  { text-align: center; font-weight: bold; }
  .pr1  { color: #c0392b; font-weight: bold; }
  .pr2  { color: #e67e22; }
  .pr3  { color: #27ae60; }
</style>
</head>
<body>

<h1>Resumen de asignaciones docentes</h1>
<p class="meta">
  Total docentes: {{ count($filas) }}
  @if($idHorario) &nbsp;|&nbsp; Horario ID: {{ $idHorario }} @endif
</p>

<table>
  <thead>
    <tr>
      <th>Docente</th>
      <th>Código</th>
      <th style="text-align:center">Prioridad</th>
      <th style="text-align:center">Secciones asignadas</th>
      <th style="text-align:center">Bloques en horario</th>
    </tr>
  </thead>
  <tbody>
    @forelse($filas as $r)
    @php
      $prClass = match((int)$r->prioridad) { 1 => 'pr1', 2 => 'pr2', default => 'pr3' };
      $prLabel  = match((int)$r->prioridad) { 1 => 'Alta', 2 => 'Media', default => 'Baja' };
    @endphp
    <tr>
      <td>{{ $r->nombre_docente }}</td>
      <td>{{ $r->codigo_docente ?? '—' }}</td>
      <td class="num {{ $prClass }}">{{ $prLabel }}</td>
      <td class="num">{{ $r->total_secciones_asignadas }}</td>
      <td class="num">{{ $r->total_bloques_horario }}</td>
    </tr>
    @empty
    <tr><td colspan="5" style="text-align:center;color:#999">Sin asignaciones registradas.</td></tr>
    @endforelse
  </tbody>
</table>

</body>
</html>
