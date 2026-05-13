# Sprint 4 — Paso 3: Dependencias para Reportes PDF/Excel

## Instalación

Ejecutar desde el directorio `backend/`:

```bash
# PDF: DomPDF para Laravel
composer require barryvdh/laravel-dompdf

# Excel: Maatwebsite Excel (PhpSpreadsheet)
composer require maatwebsite/excel
```

## Publicar configuración (opcional pero recomendado)

```bash
# DomPDF
php artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider"

# Laravel Excel
php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider" --tag=config
```

## Verificar en config/app.php (Laravel 10-)

Si no se autocargan los providers, agregar en `config/app.php`:

```php
'providers' => [
    Barryvdh\DomPDF\ServiceProvider::class,
    Maatwebsite\Excel\ExcelServiceProvider::class,
],
'aliases' => [
    'Pdf'   => Barryvdh\DomPDF\Facade\Pdf::class,
    'Excel' => Maatwebsite\Excel\Facades\Excel::class,
],
```

En **Laravel 11** (que usa `bootstrap/providers.php`) los packages se autodetectan si tienen `extra.laravel.providers` en su `composer.json`. No se requiere agregar manualmente.

## Endpoints generados

| Método | URI | Roles | Formatos |
|--------|-----|-------|---------|
| GET | `/api/reportes/horario-carrera` | admin, coord | `?formato=pdf\|excel` |
| GET | `/api/reportes/horario-docente` | admin, coord, docente | `?formato=pdf\|excel` |
| GET | `/api/reportes/secciones-no-asignadas` | admin, coord | `?formato=pdf\|excel` |
| GET | `/api/reportes/resumen-asignaciones` | admin, coord | `?formato=pdf\|excel` |

## Parámetros por endpoint

### horario-carrera
Recibe `id_horario` porque un horario identifica una **versión específica** del plan
horario de una carrera y período. La carrera y período se derivan del horario mismo.
```
?id_horario=1&formato=pdf
?id_horario=1&formato=excel
```

### horario-docente
```
# Admin / coordinador — especifican docente:
?id_docente=3&id_periodo_academico=1&formato=pdf

# Docente autenticado — id_docente se ignora del request:
?id_periodo_academico=1&id_carrera=1&formato=excel
```

### secciones-no-asignadas
```
?id_carrera=1&id_periodo_academico=1&id_horario=1&formato=pdf
```

### resumen-asignaciones
```
# Sin horario: muestra solo asignaciones (sin columna bloques)
?id_carrera=1&id_periodo_academico=1&formato=excel

# Con horario: incluye total_bloques_horario
?id_carrera=1&id_periodo_academico=1&id_horario=1&formato=pdf
```

## Fuentes DomPDF (caracteres especiales)

DomPDF incluye DejaVu Sans por defecto. Si hay caracteres con tildes que no
renderizan correctamente, publicar la configuración y verificar:

```php
// config/dompdf.php
'options' => [
    'defaultFont' => 'DejaVu Sans',
    'isRemoteEnabled' => false,
],
```
