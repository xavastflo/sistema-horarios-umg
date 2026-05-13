<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class HorarioCarreraExport implements FromArray, WithHeadings, WithTitle, WithStyles
{
    public function __construct(
        private readonly array  $detalles,
        private readonly object $horario,
    ) {}

    public function array(): array
    {
        return array_map(fn($d) => [
            $d->nombre_curso      ?? '',
            $d->codigo_curso      ?? '',
            $d->numero_seccion    ?? '',
            $d->nombre_docente    ?? '',
            $d->codigo_docente    ?? '',
            $d->nombre_dia        ?? '',
            $d->hora_inicio       ?? '',
            $d->hora_fin          ?? '',
            $d->duracion_minutos  ?? '',
            $d->nombre_jornada    ?? '',
            $d->ciclo_semestre    ?? '',
        ], $this->detalles);
    }

    public function headings(): array
    {
        return [
            'Curso',
            'Código',
            'Sección',
            'Docente',
            'Cód. Docente',
            'Día',
            'Hora inicio',
            'Hora fin',
            'Minutos',
            'Jornada',
            'Ciclo',
        ];
    }

    public function title(): string
    {
        return 'Horario';
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
