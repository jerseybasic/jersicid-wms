<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Ekspor Excel sederhana dari baris yang sudah dihitung (dipakai laporan analisis).
 */
class ArrayExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function __construct(private array $headings, private array $rows)
    {
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }
}
