<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ResumenAsignacionesExport implements FromArray, WithHeadings, WithTitle, WithStyles
{
    public function __construct(private readonly array $filas) {}

    public function array(): array
    {
        return array_map(fn($r) => [
            $r->nombre_docente            ?? '',
            $r->codigo_docente            ?? '',
            $r->prioridad                 ?? '',
            $r->total_secciones_asignadas ?? 0,
            $r->total_bloques_horario     ?? 0,
        ], $this->filas);
    }

    public function headings(): array
    {
        return [
            'Docente',
            'Código',
            'Prioridad',
            'Secciones asignadas',
            'Bloques en horario',
        ];
    }

    public function title(): string { return 'Resumen asignaciones'; }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
