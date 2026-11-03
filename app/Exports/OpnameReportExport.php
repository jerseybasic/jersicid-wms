<?php

namespace App\Exports;

use App\Models\StockOpname;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OpnameReportExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldQueue
{
    use Exportable;

    protected $request;

    public function __construct($request)
    {
        $this->request = $request;
    }

    public function query()
    {
        $query = StockOpname::query()
            ->with(['creator:id,name', 'warehouse:id,name', 'approver:id,name'])
            // Jumlah item dan total selisih dihitung sekaligus, bukan 2 query per baris
            ->withCount('items')
            ->withSum('items', 'difference')
            ->orderBy('created_at', 'desc');

        if ($this->request->filled('status')) {
            $query->where('status', $this->request->status);
        }
        if ($this->request->filled('start_date')) {
            $query->where('created_at', '>=', $this->request->start_date);
        }
        if ($this->request->filled('end_date')) {
            $query->where('created_at', '<=', $this->request->end_date . ' 23:59:59');
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'No. Opname',
            'Gudang',
            'Status',
            'Dibuat Oleh',
            'Disetujui Oleh',
            'Tanggal Dibuat',
            'Tanggal Mulai',
            'Tanggal Selesai',
            'Total Item',
            'Total Selisih',
            'Catatan'
        ];
    }

    public function map($opname): array
    {
        $totalItems = (int) $opname->items_count;
        $totalDiff = (int) $opname->items_sum_difference;

        return [
            $opname->opname_number,
            $opname->warehouse?->name ?? '-',
            $opname->status,
            $opname->creator?->name ?? '-',
            $opname->approver?->name ?? '-',
            $opname->created_at?->format('d-m-Y H:i') ?? '-',
            $opname->started_at?->format('d-m-Y H:i') ?? '-',
            $opname->completed_at?->format('d-m-Y H:i') ?? '-',
            $totalItems,
            $totalDiff,
            $opname->notes ?? '-',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0F172A']]
            ],
        ];
    }
}
