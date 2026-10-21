<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

/**
 * Cetak label langsung dari browser (tanpa harus lewat printer Zebra jaringan):
 * label SKU untuk barang yang baru diterima dan label kirim untuk paket.
 * Halaman memanggil dialog print otomatis; pilih printer label, printer biasa, atau "Simpan sebagai PDF".
 */
class LabelController extends Controller
{
    /** Batas label per cetak supaya browser tidak berat */
    private const MAX_LABELS = 300;

    /**
     * Label SKU. ?ref=<no. surat jalan inbound> mencetak 1 label per pcs yang diterima,
     * atau ?sku=<SKU>&qty=<jumlah> untuk satu SKU.
     * ?ukuran=a4 mencetak grid di kertas A4 untuk printer biasa; default stiker 50×30 mm.
     */
    public function sku(Request $request)
    {
        $validated = $request->validate([
            'ref' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'exists:master_skus,sku'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_LABELS],
            'ukuran' => ['nullable', 'in:stiker,a4'],
        ]);

        $lines = collect();

        if (!empty($validated['ref'])) {
            $lines = StockMovement::where('type', 'INBOUND')
                ->where('reference_no', $validated['ref'])
                ->with('masterSku')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($movement) => ['sku' => $movement->masterSku, 'bin' => $movement->bin_code, 'qty' => $movement->qty]);
        } elseif (!empty($validated['sku'])) {
            $lines = collect([[
                'sku' => MasterSku::where('sku', $validated['sku'])->first(),
                'bin' => null,
                'qty' => $validated['qty'] ?? 1,
            ]]);
        }

        $lines = $lines->filter(fn ($line) => $line['sku'] !== null)->values();
        abort_if($lines->isEmpty(), 404, 'Tidak ada barang untuk dicetak.');

        $labels = [];
        foreach ($lines as $line) {
            for ($i = 0; $i < $line['qty'] && count($labels) < self::MAX_LABELS; $i++) {
                $labels[] = $line;
            }
        }

        return view('warehouse.label.sku', [
            'labels' => $labels,
            'total' => $lines->sum('qty'),
            'truncated' => $lines->sum('qty') > self::MAX_LABELS,
            'size' => $validated['ukuran'] ?? 'stiker',
            'title' => !empty($validated['ref']) ? 'Label SKU ' . $validated['ref'] : 'Label SKU ' . $validated['sku'],
        ]);
    }

    /**
     * Label kirim 100×150 mm untuk order yang sudah dikemas.
     */
    public function shipping($orderId)
    {
        $order = Order::with('items.masterSku')->findOrFail($orderId);

        abort_unless(
            in_array($order->status, ['READY_TO_SHIP', 'SHIPPED', 'COMPLETED']),
            422,
            'Label kirim baru bisa dicetak setelah packing selesai.'
        );

        return view('warehouse.label.shipping', [
            'order' => $order,
            'senderName' => SystemSetting::get('company_name', config('app.name')),
            'senderPhone' => SystemSetting::get('company_phone'),
            'senderAddress' => SystemSetting::get('company_address'),
        ]);
    }
}
