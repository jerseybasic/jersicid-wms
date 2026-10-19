<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\BarcodeScan;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BarcodeScannerController extends Controller
{
    public function index()
    {
        $recentScans = BarcodeScan::with(['user:id,name', 'masterSku:sku,product_name'])
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        $todayStats = [
            'total_scans' => BarcodeScan::where('user_id', auth()->id())
                ->whereDate('created_at', today())->count(),
            'success' => BarcodeScan::where('user_id', auth()->id())
                ->whereDate('created_at', today())
                ->where('status', 'SUCCESS')->count(),
            'failed' => BarcodeScan::where('user_id', auth()->id())
                ->whereDate('created_at', today())
                ->where('status', 'FAILED')->count(),
        ];

        return view('warehouse.scanner.index', compact('recentScans', 'todayStats'));
    }

    /**
     * Validate barcode - untuk preview sebelum scan
     */
    public function validate(Request $request)
    {
        try {
            $barcode = strtoupper(trim($request->get('barcode', '')));

            if (empty($barcode)) {
                return response()->json([
                    'valid' => false,
                    'message' => 'Barcode kosong',
                ]);
            }

            $sku = MasterSku::where('sku', $barcode)->first();

            if ($sku) {
                $stock = StockActive::where('sku', $sku->sku)->sum('qty');

                return response()->json([
                    'valid' => true,
                    'sku' => $sku->sku,
                    'product_name' => $sku->product_name,
                    'size' => $sku->size,
                    'article' => $sku->article,
                    'price' => $sku->price,
                    'current_stock' => $stock,
                    'min_stock' => $sku->min_stock,
                    'status' => $sku->status,
                ]);
            }

            return response()->json([
                'valid' => false,
                'message' => 'SKU tidak ditemukan: ' . $barcode,
            ]);
        } catch (\Exception $e) {
            Log::error('Validate Barcode Error: ' . $e->getMessage());

            return response()->json([
                'valid' => false,
                'message' => 'Error: ' . safe_error_message($e),
            ], 500);
        }
    }

    /**
     * Add to buffer - tidak langsung proses ke database
     */
    public function addToBuffer(Request $request)
    {
        try {
            $validated = $request->validate([
                'barcode' => 'required|string',
                'scan_type' => 'required|in:INBOUND,OUTBOUND,PICKING,PACKING,OPNAME,TRANSFER,QC_CHECK,RETURN_IN,RETURN_OUT',
                'qty' => 'required|integer|min:1',
                'bin_code' => 'nullable|string|max:50',
                'reference_no' => 'nullable|string|max:100',
                'notes' => 'nullable|string|max:500',
            ]);

            $barcode = strtoupper(trim($validated['barcode']));
            $sku = MasterSku::where('sku', $barcode)->first();

            // Simpan ke session buffer
            $buffer = session()->get('scan_buffer', []);

            $bufferItem = [
                'id' => Str::uuid()->toString(),
                'barcode' => $barcode,
                'sku' => $sku?->sku ?? $barcode,
                'product_name' => $sku?->product_name ?? 'Unknown',
                'size' => $sku?->size ?? '-',
                'scan_type' => $validated['scan_type'],
                'qty' => $validated['qty'],
                'bin_code' => $validated['bin_code'] ?? null,
                'reference_no' => $validated['reference_no'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => $sku ? 'PENDING' : 'INVALID',
                'message' => $sku ? 'Menunggu konfirmasi' : 'SKU tidak ditemukan',
                'created_at' => now()->toDateTimeString(),
            ];

            array_unshift($buffer, $bufferItem);

            // Batasi buffer max 100 items
            $buffer = array_slice($buffer, 0, 100);

            session()->put('scan_buffer', $buffer);

            return response()->json([
                'success' => true,
                'message' => $sku ? 'Ditambahkan ke buffer' : 'SKU tidak ditemukan - perlu review',
                'item' => $bufferItem,
                'buffer_count' => count($buffer),
            ]);
        } catch (\Exception $e) {
            Log::error('Add to Buffer Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . safe_error_message($e),
            ], 500);
        }
    }

    /**
     * Get buffer items
     */
    public function getBuffer(Request $request)
    {
        $buffer = session()->get('scan_buffer', []);

        return response()->json([
            'success' => true,
            'items' => $buffer,
            'count' => count($buffer),
        ]);
    }

    /**
     * Update buffer item
     */
    public function updateBuffer(Request $request, $id)
    {
        try {
            $buffer = session()->get('scan_buffer', []);

            $key = array_search($id, array_column($buffer, 'id'));

            if ($key === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item tidak ditemukan di buffer',
                ], 404);
            }

            $validated = $request->validate([
                'qty' => 'required|integer|min:1',
                'bin_code' => 'nullable|string|max:50',
                'reference_no' => 'nullable|string|max:100',
                'notes' => 'nullable|string|max:500',
            ]);

            $buffer[$key]['qty'] = $validated['qty'];
            $buffer[$key]['bin_code'] = $validated['bin_code'];
            $buffer[$key]['reference_no'] = $validated['reference_no'];
            $buffer[$key]['notes'] = $validated['notes'];

            session()->put('scan_buffer', $buffer);

            return response()->json([
                'success' => true,
                'message' => 'Item berhasil diupdate',
                'item' => $buffer[$key],
            ]);
        } catch (\Exception $e) {
            Log::error('Update Buffer Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . safe_error_message($e),
            ], 500);
        }
    }

    /**
     * Delete buffer item
     */
    public function deleteBuffer($id)
    {
        try {
            $buffer = session()->get('scan_buffer', []);

            $buffer = array_filter($buffer, function ($item) use ($id) {
                return $item['id'] !== $id;
            });

            $buffer = array_values($buffer);
            session()->put('scan_buffer', $buffer);

            return response()->json([
                'success' => true,
                'message' => 'Item dihapus dari buffer',
                'count' => count($buffer),
            ]);
        } catch (\Exception $e) {
            Log::error('Delete Buffer Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . safe_error_message($e),
            ], 500);
        }
    }

    /**
     * Clear all buffer
     */
    public function clearBuffer()
    {
        session()->forget('scan_buffer');

        return response()->json([
            'success' => true,
            'message' => 'Buffer dikosongkan',
        ]);
    }

    /**
     * Confirm all buffer items - proses ke database
     */
    public function confirmBuffer(Request $request)
    {
        try {
            $buffer = session()->get('scan_buffer', []);

            if (empty($buffer)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Buffer kosong',
                ]);
            }

            $processed = 0;
            $failed = 0;
            $errors = [];

            DB::transaction(function () use ($buffer, &$processed, &$failed, &$errors) {
                foreach ($buffer as $item) {
                    try {
                        if ($item['status'] === 'INVALID') {
                            $failed++;
                            $errors[] = "SKU {$item['barcode']} tidak valid";
                            continue;
                        }

                        $scan = BarcodeScan::create([
                            'user_id' => auth()->id(),
                            'sku' => $item['sku'],
                            'bin_code' => $item['bin_code'],
                            'scan_type' => $item['scan_type'],
                            'qty' => $item['qty'],
                            'reference_no' => $item['reference_no'],
                            'status' => 'SUCCESS',
                            'message' => 'Confirmed from buffer',
                            'notes' => $item['notes'],
                            'scanner_device' => 'Inforce Wireless 2D',
                            'ip_address' => request()->ip(),
                        ]);

                        $sku = MasterSku::where('sku', $item['sku'])->first();

                        if ($sku) {
                            $this->processStockMovement($scan, $sku, $item);
                        }

                        $processed++;
                    } catch (\Exception $e) {
                        $failed++;
                        $errors[] = "{$item['barcode']}: " . safe_error_message($e);
                        Log::error('Process Buffer Item Error: ' . $e->getMessage());
                    }
                }
            });

            // Clear buffer setelah diproses
            session()->forget('scan_buffer');

            return response()->json([
                'success' => true,
                'message' => "Diproses: {$processed}, Gagal: {$failed}",
                'processed' => $processed,
                'failed' => $failed,
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            Log::error('Confirm Buffer Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . safe_error_message($e),
            ], 500);
        }
    }

    /**
     * Edit scan record (dari database)
     */
    public function updateScan(Request $request, $id)
    {
        $scan = BarcodeScan::findOrFail($id);
        $this->authorizeScan($scan);

        try {
            $validated = $request->validate([
                'qty' => 'required|integer|min:1',
                'bin_code' => 'nullable|string|max:50',
                'reference_no' => 'nullable|string|max:100',
                'notes' => 'nullable|string|max:500',
            ]);

            $oldValues = $scan->only(array_keys($validated));
            $scan->update($validated);

            AuditTrail::create([
                'user_id' => auth()->id(),
                'action' => 'UPDATE',
                'module' => 'BARCODE_SCAN',
                'old_values' => ['id' => $scan->id, 'sku' => $scan->sku] + $oldValues,
                'new_values' => $validated,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Scan record berhasil diupdate',
            ]);
        } catch (\Exception $e) {
            Log::error('Update Scan Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . safe_error_message($e),
            ], 500);
        }
    }

    /**
     * Delete scan record
     */
    public function deleteScan(Request $request, $id)
    {
        $scan = BarcodeScan::findOrFail($id);
        $this->authorizeScan($scan);

        try {

            $scan->delete();

            AuditTrail::create([
                'user_id' => auth()->id(),
                'action' => 'DELETE',
                'module' => 'BARCODE_SCAN',
                'old_values' => $scan->only(['id', 'sku', 'bin_code', 'qty', 'scan_type', 'user_id']),
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Scan record berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete Scan Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . safe_error_message($e),
            ], 500);
        }
    }

    /**
     * Staf hanya boleh mengubah scan miliknya sendiri; OWNER/SUPERVISOR boleh semua.
     */
    private function authorizeScan(BarcodeScan $scan): void
    {
        $user = auth()->user();

        if (!in_array($user->role, ['OWNER', 'SUPERVISOR']) && $scan->user_id !== $user->id) {
            abort(403, 'Anda hanya bisa mengubah data scan milik Anda sendiri.');
        }
    }

    /**
     * Get recent scans
     */
    public function recentScans(Request $request)
    {
        $scans = BarcodeScan::with(['user:id,name', 'masterSku:sku,product_name'])
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->limit($request->get('limit', 20))
            ->get();

        return response()->json($scans);
    }

    /**
     * Check scanner device status
     */
    public function checkDevice(Request $request)
    {
        // Cek apakah ada aktivitas scanner dalam 30 detik terakhir
        $lastActivity = BarcodeScan::where('user_id', auth()->id())
            ->where('scanner_device', 'like', '%Inforce%')
            ->latest()
            ->first();

        $isActive = $lastActivity && $lastActivity->created_at->diffInSeconds(now()) < 30;

        return response()->json([
            'connected' => $isActive,
            'device' => 'Inforce Wireless 2D Scanner',
            'last_activity' => $lastActivity?->created_at?->toDateTimeString(),
            'mode' => 'HID (Keyboard Emulation)',
        ]);
    }

    protected function processStockMovement(BarcodeScan $scan, MasterSku $sku, array $data): void
    {
        $binCode = $data['bin_code'] ?? 'DEFAULT-01';
        $qty = $data['qty'];

        switch ($scan->scan_type) {
            case 'INBOUND':
                $stockActive = StockActive::where('sku', $sku->sku)
                    ->where('bin_code', $binCode)
                    ->first();

                if ($stockActive) {
                    $oldQty = $stockActive->qty;
                    $stockActive->update([
                        'qty' => $oldQty + $qty,
                        'last_movement_at' => now(),
                    ]);
                } else {
                    StockActive::create([
                        'id' => (string) Str::uuid(),
                        'sku' => $sku->sku,
                        'bin_code' => $binCode,
                        'qty' => $qty,
                        'reserved_qty' => 0,
                        'received_at' => now(),
                        'last_movement_at' => now(),
                    ]);
                }

                StockMovement::create([
                    'user_id' => $scan->user_id,
                    'sku' => $sku->sku,
                    'bin_code' => $binCode,
                    'type' => 'INBOUND',
                    'qty' => $qty,
                    'qty_before' => $stockActive?->qty ?? 0,
                    'qty_after' => ($stockActive?->qty ?? 0) + $qty,
                    'reference_no' => $data['reference_no'] ?? 'SCAN-' . date('YmdHis'),
                    'notes' => 'Barcode scan inbound',
                    'ip_address' => request()->ip(),
                ]);
                break;

            case 'OUTBOUND':
                $stockActive = StockActive::where('sku', $sku->sku)
                    ->where('bin_code', $binCode)
                    ->where('qty', '>=', $qty)
                    ->first();

                if ($stockActive) {
                    $oldQty = $stockActive->qty;
                    $stockActive->update([
                        'qty' => $oldQty - $qty,
                        'last_movement_at' => now(),
                    ]);

                    StockMovement::create([
                        'user_id' => $scan->user_id,
                        'sku' => $sku->sku,
                        'bin_code' => $binCode,
                        'type' => 'OUTBOUND',
                        'qty' => $qty,
                        'qty_before' => $oldQty,
                        'qty_after' => $oldQty - $qty,
                        'reference_no' => $data['reference_no'] ?? 'SCAN-' . date('YmdHis'),
                        'notes' => 'Barcode scan outbound',
                        'ip_address' => request()->ip(),
                    ]);
                } else {
                    $scan->update([
                        'status' => 'FAILED',
                        'message' => 'Stok tidak mencukupi di bin ' . $binCode,
                    ]);
                }
                break;

            case 'PICKING':
                $stockActive = StockActive::where('sku', $sku->sku)
                    ->where('bin_code', $binCode)
                    ->first();

                if ($stockActive && $stockActive->qty >= $qty) {
                    $oldQty = $stockActive->qty;
                    $stockActive->update([
                        'qty' => $oldQty - $qty,
                        'reserved_qty' => $stockActive->reserved_qty + $qty,
                        'last_movement_at' => now(),
                    ]);

                    StockMovement::create([
                        'user_id' => $scan->user_id,
                        'sku' => $sku->sku,
                        'bin_code' => $binCode,
                        'type' => 'PICKING',
                        'qty' => $qty,
                        'qty_before' => $oldQty,
                        'qty_after' => $oldQty - $qty,
                        'reference_no' => $data['reference_no'] ?? 'PICK-' . date('YmdHis'),
                        'notes' => 'Barcode scan picking',
                        'ip_address' => request()->ip(),
                    ]);
                }
                break;

            case 'PACKING':
                $stockActive = StockActive::where('sku', $sku->sku)
                    ->where('reserved_qty', '>=', $qty)
                    ->first();

                if ($stockActive) {
                    $stockActive->update([
                        'reserved_qty' => $stockActive->reserved_qty - $qty,
                        'last_movement_at' => now(),
                    ]);

                    StockMovement::create([
                        'user_id' => $scan->user_id,
                        'sku' => $sku->sku,
                        'bin_code' => $binCode,
                        'type' => 'PACKING',
                        'qty' => $qty,
                        'qty_before' => $stockActive->reserved_qty + $qty,
                        'qty_after' => $stockActive->reserved_qty,
                        'reference_no' => $data['reference_no'] ?? 'PACK-' . date('YmdHis'),
                        'notes' => 'Barcode scan packing',
                        'ip_address' => request()->ip(),
                    ]);
                }
                break;

            case 'OPNAME':
                $stockActive = StockActive::where('sku', $sku->sku)
                    ->where('bin_code', $binCode)
                    ->first();

                if ($stockActive) {
                    $oldQty = $stockActive->qty;
                    $stockActive->update([
                        'qty' => $qty,
                        'last_movement_at' => now(),
                    ]);

                    StockMovement::create([
                        'user_id' => $scan->user_id,
                        'sku' => $sku->sku,
                        'bin_code' => $binCode,
                        'type' => 'ADJUSTMENT',
                        'qty' => abs($qty - $oldQty),
                        'qty_before' => $oldQty,
                        'qty_after' => $qty,
                        'reference_no' => 'OPNAME-' . date('YmdHis'),
                        'notes' => 'Stock opname via barcode scan',
                        'ip_address' => request()->ip(),
                    ]);
                }
                break;
        }
    }
}
