<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Workbook con dos hojas: una por categoría de sección no asignada.
 */
class SeccionesNoAsignadasExport implements WithMultipleSheets
{
    public function __construct(private readonly array $datos) {}

    public function sheets(): array
    {
        return [
            new SeccionesSheet($this->datos['sin_docente'],           'Sin docente'),
            new SeccionesSheet($this->datos['sin_bloque_en_horario'], 'Sin bloque en horario'),
        ];
    }
}

class SeccionesSheet implements FromArray, WithHeadings, WithTitle, WithStyles
{
    public function __construct(
        private readonly array  $filas,
        private readonly string $titulo,
    ) {}

    public function array(): array
    {
        return array_map(fn($r) => [
            $r->nombre_curso   ?? '',
            $r->codigo_curso   ?? '',
            $r->numero_seccion ?? '',
            $r->ciclo_semestre ?? '',
            $r->categoria      ?? '',
            $r->motivo         ?? '',
        ], $this->filas);
    }

    public function headings(): array
    {
        return ['Curso', 'Código', 'Sección', 'Ciclo', 'Categoría', 'Motivo'];
    }

    public function title(): string { return $this->titulo; }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
