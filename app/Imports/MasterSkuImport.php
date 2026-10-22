<?php

namespace App\Imports;

use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterImport;

class MasterSkuImport implements ToModel, WithHeadingRow, SkipsEmptyRows, WithBatchInserts, WithChunkReading, SkipsOnError, WithEvents
{
    use SkipsFailures;

    protected $imported = 0;
    protected $updated = 0;
    protected $skipped = 0;
    protected $stockCreated = 0;
    protected $pendingStocks = [];

    // ✅ TAMBAHAN: Catat baris yang dilewati beserta alasannya
    protected $skippedRows = [];
    protected $rowNumber = 1; // Dimulai dari 1 karena row 1 adalah header

    public function model(array $row): ?Model
    {
        $this->rowNumber++; // Increment untuk setiap baris data

        $sku = strtoupper(trim($row['sku'] ?? ''));

        // ✅ Skip baris kosong dengan catatan
        if (empty($sku)) {
            $this->skipped++;
            $this->skippedRows[] = [
                'row' => $this->rowNumber,
                'reason' => 'SKU kosong',
                'data' => $row,
            ];
            return null;
        }

        $existingSku = MasterSku::where('sku', $sku)->first();

        $data = [
            'sku' => $sku,
            'article' => strtoupper(trim($row['article'] ?? '')),
            'product_name' => trim($row['product_name'] ?? ''),
            'version' => trim($row['version'] ?? '1.0'),
            'sub_version' => !empty($row['sub_version']) ? trim($row['sub_version']) : null,
            'size_category' => strtoupper(trim($row['size_category'] ?? 'DEWASA')),
            'size' => strtoupper(trim($row['size'] ?? '')),
            'size_token' => !empty($row['size_token']) ? trim($row['size_token']) : null,
            'price' => (int) ($row['price'] ?? 0),
            'standard_weight_gram' => (int) ($row['standard_weight_gram'] ?? 250),
            'min_stock' => (int) ($row['min_stock'] ?? 0),
            'status' => strtoupper(trim($row['status'] ?? 'AKTIF')),
        ];

        // Harga modal hanya diisi kalau kolomnya ada di file, supaya file lama tidak menghapusnya
        $cost = MasterSku::costFromRow($row);
        if ($cost !== null || !$existingSku) {
            $data['cost_price'] = $cost ?? 0;
        }

        $currentStock = (int) ($row['current_stock'] ?? 0);

        if ($existingSku) {
            // ✅ Update SKU yang sudah ada (bukan skip, tapi update)
            $existingSku->update($data);
            $this->updated++;

            if ($currentStock > 0) {
                $this->handleStockUpdate($existingSku, $currentStock);
            }

            return null;
        }

        // ✅ Validasi data wajib sebelum create
        if (empty($data['product_name'])) {
            $this->skipped++;
            $this->skippedRows[] = [
                'row' => $this->rowNumber,
                'sku' => $sku,
                'reason' => 'Nama produk kosong',
            ];
            return null;
        }

        if (!in_array($data['size_category'], ['DEWASA', 'KIDS', 'TEENS'])) {
            $this->skipped++;
            $this->skippedRows[] = [
                'row' => $this->rowNumber,
                'sku' => $sku,
                'reason' => 'Kategori size tidak valid (harus DEWASA/KIDS/TEENS)',
            ];
            return null;
        }

        if (!in_array($data['status'], ['AKTIF', 'NONAKTIF'])) {
            $this->skipped++;
            $this->skippedRows[] = [
                'row' => $this->rowNumber,
                'sku' => $sku,
                'reason' => 'Status tidak valid (harus AKTIF/NONAKTIF)',
            ];
            return null;
        }

        $this->imported++;

        // Simpan data stok sementara untuk diproses di afterImport
        $this->pendingStocks[] = [
            'sku' => $sku,
            'data' => $data,
            'stock' => $currentStock,
        ];

        return new MasterSku($data);
    }

    public function registerEvents(): array
    {
        return [
            AfterImport::class => function (AfterImport $event) {
                // Proses semua SKU baru yang pending
                foreach ($this->pendingStocks as $pending) {
                    $sku = MasterSku::where('sku', $pending['sku'])->first();
                    if ($sku && $pending['stock'] > 0) {
                        $this->createStockForNewSku($sku, $pending['stock']);
                    }
                }
                $this->pendingStocks = [];
            },
        ];
    }

    protected function createStockForNewSku(MasterSku $sku, int $qty): void
    {
        $stockActive = StockActive::where('sku', $sku->sku)->first();

        if ($stockActive) {
            $oldQty = $stockActive->qty;
            $stockActive->update([
                'qty' => $qty,
                'last_movement_at' => now(),
            ]);

            if ($oldQty !== $qty) {
                StockMovement::create([
                    'user_id' => auth()->id(),
                    'sku' => $sku->sku,
                    'bin_code' => $stockActive->bin_code,
                    'type' => 'ADJUSTMENT',
                    'qty' => abs($qty - $oldQty),
                    'qty_before' => $oldQty,
                    'qty_after' => $qty,
                    'reference_no' => 'IMPORT-' . date('YmdHis'),
                    'notes' => 'Stock adjustment via Excel Import',
                    'ip_address' => request()->ip(),
                ]);
            }
        } else {
            StockActive::create([
                'id' => (string) Str::uuid(),
                'sku' => $sku->sku,
                'bin_code' => 'DEFAULT-01',
                'qty' => $qty,
                'reserved_qty' => 0,
                'received_at' => now(),
                'last_movement_at' => now(),
            ]);

            StockMovement::create([
                'user_id' => auth()->id(),
                'sku' => $sku->sku,
                'bin_code' => 'DEFAULT-01',
                'type' => 'INBOUND',
                'qty' => $qty,
                'qty_before' => 0,
                'qty_after' => $qty,
                'reference_no' => 'IMPORT-' . date('YmdHis'),
                'notes' => 'Initial stock via Excel Import',
                'ip_address' => request()->ip(),
            ]);
        }

        $this->stockCreated++;
    }

    protected function handleStockUpdate(MasterSku $sku, int $qty): void
    {
        $stockActive = StockActive::where('sku', $sku->sku)->first();

        if ($stockActive) {
            $oldQty = $stockActive->qty;
            $stockActive->update([
                'qty' => $qty,
                'last_movement_at' => now(),
            ]);

            if ($oldQty !== $qty) {
                StockMovement::create([
                    'user_id' => auth()->id(),
                    'sku' => $sku->sku,
                    'bin_code' => $stockActive->bin_code,
                    'type' => 'ADJUSTMENT',
                    'qty' => abs($qty - $oldQty),
                    'qty_before' => $oldQty,
                    'qty_after' => $qty,
                    'reference_no' => 'IMPORT-' . date('YmdHis'),
                    'notes' => 'Stock adjustment via Excel Import',
                    'ip_address' => request()->ip(),
                ]);
            }
        } else {
            StockActive::create([
                'id' => (string) Str::uuid(),
                'sku' => $sku->sku,
                'bin_code' => 'DEFAULT-01',
                'qty' => $qty,
                'reserved_qty' => 0,
                'received_at' => now(),
                'last_movement_at' => now(),
            ]);

            StockMovement::create([
                'user_id' => auth()->id(),
                'sku' => $sku->sku,
                'bin_code' => 'DEFAULT-01',
                'type' => 'INBOUND',
                'qty' => $qty,
                'qty_before' => 0,
                'qty_after' => $qty,
                'reference_no' => 'IMPORT-' . date('YmdHis'),
                'notes' => 'Initial stock via Excel Import',
                'ip_address' => request()->ip(),
            ]);
        }

        $this->stockCreated++;
    }

    public function rules(): array
    {
        return [
            'sku' => ['nullable', 'string', 'max:100'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'size_category' => ['nullable', 'in:DEWASA,KIDS,TEENS'],
            'size' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'in:AKTIF,NONAKTIF'],
        ];
    }

    public function customValidationMessages()
    {
        return [
            'sku.string' => 'SKU harus berupa teks',
            'size_category.in' => 'Size Category harus DEWASA, KIDS, atau TEENS',
            'status.in' => 'Status harus AKTIF atau NONAKTIF',
        ];
    }

    public function batchSize(): int
    {
        return 100;
    }
    public function chunkSize(): int
    {
        return 100;
    }

    public function getImportedCount()
    {
        return $this->imported;
    }
    public function getUpdatedCount()
    {
        return $this->updated;
    }
    public function getSkippedCount()
    {
        return $this->skipped;
    }
    public function getStockCreatedCount()
    {
        return $this->stockCreated;
    }

    // ✅ TAMBAHAN: Getter untuk baris yang dilewati
    public function getSkippedRows()
    {
        return $this->skippedRows;
    }

    public function getErrors()
    {
        return $this->failures();
    }

    public function onError(\Throwable $e): void
    {
        // Auto-handled by SkipsFailures trait
    }
}
