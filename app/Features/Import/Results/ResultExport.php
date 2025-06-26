<?php

namespace App\Features\Import\Results;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ResultExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function __construct(protected array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return array_keys($this->rows[0] ?? []);
    }
}
