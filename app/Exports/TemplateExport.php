<?php

namespace App\Exports;

use App\Models\MasterSku;
use App\Models\StockActive;
use Illuminate\Support\Collection; // ✅ PENTING: Import Collection
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class TemplateExport implements FromCollection, WithHeadings, WithStyles, WithColumnFormatting
{
    protected $includeStock;

    public function __construct($includeStock = false)
    {
        $this->includeStock = $includeStock;
    }

    /**
     * ✅ PERBAIKAN: Tambahkan return type `: Collection`
     */
    public function collection(): Collection
    {
        if ($this->includeStock) {
            // Include stok saat ini dari database
            return MasterSku::select(
                'master_skus.sku',
                'master_skus.article',
                'master_skus.product_name',
                'master_skus.version',
                'master_skus.sub_version',
                'master_skus.size_category',
                'master_skus.size',
                'master_skus.size_token',
                'master_skus.price',
                'master_skus.cost_price',
                'master_skus.standard_weight_gram',
                'master_skus.min_stock',
                'master_skus.status'
            )
                ->leftJoin('stock_actives', 'master_skus.sku', '=', 'stock_actives.sku')
                ->selectRaw('COALESCE(SUM(stock_actives.qty), 0) as current_stock')
                ->where('master_skus.status', 'AKTIF')
                ->groupBy(
                    'master_skus.sku',
                    'master_skus.article',
                    'master_skus.product_name',
                    'master_skus.version',
                    'master_skus.sub_version',
                    'master_skus.size_category',
                    'master_skus.size',
                    'master_skus.size_token',
                    'master_skus.price',
                    'master_skus.cost_price',
                    'master_skus.standard_weight_gram',
                    'master_skus.min_stock',
                    'master_skus.status'
                )
                ->orderBy('master_skus.sku')
                ->limit(1000)
                ->get(); // ->get() mengembalikan Collection
        }

        // Template kosong dengan contoh data (Manual Collection)
        return collect([
            [
                'sku' => 'JER-BASIC-L',
                'article' => 'BASIC',
                'product_name' => 'Jersey Basic Premium',
                'version' => '1.0',
                'sub_version' => null,
                'size_category' => 'DEWASA',
                'size' => 'L',
                'size_token' => 'L',
                'price' => 150000,
                'cost_price' => 85000,
                'standard_weight_gram' => 250,
                'min_stock' => 10,
                'status' => 'AKTIF',
                'current_stock' => 0,
            ],
            [
                'sku' => 'JER-KIDS-M',
                'article' => 'KIDS',
                'product_name' => 'Jersey Kids Fun',
                'version' => '1.0',
                'sub_version' => null,
                'size_category' => 'KIDS',
                'size' => 'M',
                'size_token' => 'M',
                'price' => 120000,
                'cost_price' => 65000,
                'standard_weight_gram' => 200,
                'min_stock' => 5,
                'status' => 'AKTIF',
                'current_stock' => 0,
            ],
        ]);
    }

    public function headings(): array
    {
        $headings = [
            'SKU',
            'ARTICLE',
            'PRODUCT_NAME',
            'VERSION',
            'SUB_VERSION',
            'SIZE_CATEGORY',
            'SIZE',
            'SIZE_TOKEN',
            'PRICE',
            'COST_PRICE',
            'STANDARD_WEIGHT_GRAM',
            'MIN_STOCK',
            'STATUS'
        ];

        if ($this->includeStock) {
            $headings[] = 'CURRENT_STOCK';
        }

        return $headings;
    }

    public function styles(Worksheet $sheet): ?array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF06B6D4']
                ]
            ],
        ];
    }

    public function columnFormats(): array
    {
        return [
            'I' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // PRICE
            'J' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // COST_PRICE
            'K' => NumberFormat::FORMAT_NUMBER, // STANDARD_WEIGHT_GRAM
            'L' => NumberFormat::FORMAT_NUMBER, // MIN_STOCK
            'N' => NumberFormat::FORMAT_NUMBER, // CURRENT_STOCK (jika ada)
        ];
    }
}
