<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class HorarioDocenteExport implements FromArray, WithHeadings, WithTitle, WithStyles
{
    public function __construct(private readonly array $clases) {}

    public function array(): array
    {
        return array_map(fn($d) => [
            $d->nombre_carrera   ?? '',
            $d->nombre_periodo   ?? '',
            $d->estado_horario   ?? '',
            $d->nombre_curso     ?? '',
            $d->codigo_curso     ?? '',
            $d->numero_seccion   ?? '',
            $d->nombre_dia       ?? '',
            $d->hora_inicio      ?? '',
            $d->hora_fin         ?? '',
            $d->nombre_jornada   ?? '',
            $d->ciclo_semestre   ?? '',
        ], $this->clases);
    }

    public function headings(): array
    {
        return [
            'Carrera', 'Período', 'Estado horario',
            'Curso', 'Código', 'Sección',
            'Día', 'Hora inicio', 'Hora fin',
            'Jornada', 'Ciclo',
        ];
    }

    public function title(): string { return 'Horario docente'; }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
