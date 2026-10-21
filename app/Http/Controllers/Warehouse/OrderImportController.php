<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\BigSellerOrderImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import pesanan marketplace dari file ekspor BigSeller.
 * Langkah 1 upload → pratinjau (pesanan bermasalah ditandai), langkah 2 simpan pesanan yang lolos.
 */
class OrderImportController extends Controller
{
    private const CACHE_MINUTES = 60;

    public function create()
    {
        return view('warehouse.outbound.import');
    }

    public function preview(Request $request, BigSellerOrderImport $import)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ], [
            'file.mimes' => 'File harus Excel (.xlsx/.xls) atau CSV dari ekspor BigSeller.',
        ]);

        try {
            $orders = $import->parse($request->file('file')->getRealPath());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'File tidak bisa dibaca: ' . safe_error_message($e));
        }

        if (!$orders) {
            return back()->with('error', 'Tidak ada pesanan di file ini.');
        }

        $token = (string) Str::uuid();
        Cache::put($this->cacheKey($token), $orders, now()->addMinutes(self::CACHE_MINUTES));

        return view('warehouse.outbound.import-preview', [
            'orders' => $orders,
            'token' => $token,
            'fileName' => $request->file('file')->getClientOriginalName(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'confirm_now' => ['nullable', 'boolean'],
        ]);

        $orders = Cache::pull($this->cacheKey($validated['token']));
        if (!$orders) {
            return redirect()->route('gudang.outbound.import')
                ->with('error', 'Pratinjau sudah kedaluwarsa. Upload ulang filenya.');
        }

        $status = $request->boolean('confirm_now') ? 'CONFIRMED' : 'PENDING';
        $created = [];
        $skipped = 0;

        DB::transaction(function () use ($orders, $status, $request, &$created, &$skipped) {
            foreach ($orders as $data) {
                // Cek ulang saat menyimpan: bisa saja sudah diimpor dari tab lain
                if (!$data['importable'] || Order::withTrashed()->where('order_number', $data['order_number'])->exists()) {
                    $skipped++;
                    continue;
                }

                $order = Order::create([
                    'order_number' => $data['order_number'],
                    'customer_name' => Str::limit($data['customer_name'], 250, ''),
                    'customer_phone' => $data['customer_phone'],
                    'shipping_address' => $data['shipping_address'],
                    'courier' => $data['courier'],
                    'tracking_number' => $data['tracking_number'],
                    'status' => $status,
                    'notes' => $this->notes($data),
                    'created_by' => auth()->id(),
                ]);

                foreach ($data['items'] as $item) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'sku' => $item['sku'],
                        'qty_ordered' => $item['qty'],
                        'qty_picked' => 0,
                        'qty_packed' => 0,
                        'price' => $item['price'],
                    ]);
                }

                $created[] = $order->order_number;
            }

            AuditTrail::create([
                'user_id' => auth()->id(),
                'action' => 'IMPORT',
                'module' => 'OUTBOUND',
                'new_values' => json_encode(['source' => 'BigSeller', 'status' => $status, 'orders' => $created]),
                'ip_address' => $request->ip(),
            ]);
        });

        $message = count($created) . ' pesanan berhasil diimpor' . ($status === 'CONFIRMED' ? ' dan langsung masuk daftar picking.' : '.');
        if ($skipped) {
            $message .= " {$skipped} pesanan dilewati.";
        }

        return redirect()->route('gudang.outbound')->with(count($created) ? 'success' : 'error', $message);
    }

    private function notes(array $data): string
    {
        return collect([
            'Import BigSeller' . ($data['marketplace'] ? " ({$data['marketplace']})" : ''),
            $data['buyer'] ? "Pembeli: {$data['buyer']}" : null,
            $data['ordered_at'] ? "Dipesan: {$data['ordered_at']}" : null,
            $data['buyer_message'] ? "Pesan pembeli: {$data['buyer_message']}" : null,
            $data['seller_note'] ? "Catatan penjual: {$data['seller_note']}" : null,
        ])->filter()->implode("\n");
    }

    private function cacheKey(string $token): string
    {
        return 'bigseller-import:' . auth()->id() . ':' . $token;
    }
}
