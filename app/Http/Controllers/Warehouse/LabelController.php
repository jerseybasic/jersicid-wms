<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\Order;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\SystemSetting;
use App\Models\WarehouseRack;
use App\Services\ScanCommand;
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

    /** Ukuran label QR rak/bin/perintah */
    private const QR_SIZES = ['stiker', 'sedang', 'besar', 'a4'];

    /**
     * Label SKU. ?ref=<no. surat jalan inbound atau no. retur> mencetak 1 label per pcs yang masuk,
     * atau ?sku=<SKU>&qty=<jumlah> untuk satu SKU.
     * ?ukuran=a4 mencetak grid di kertas A4 untuk printer biasa; default stiker 50×30 mm.
     */
    public function sku(Request $request)
    {
        $validated = $request->validate([
            'ref' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'exists:master_skus,sku'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_LABELS],
            'daftar' => ['nullable', 'string', 'max:20000'],
            'cari' => ['nullable', 'string', 'max:100'],
            'ukuran' => ['nullable', 'in:stiker,a4'],
        ]);

        $lines = collect();

        if (!empty($validated['ref'])) {
            $lines = StockMovement::whereIn('type', ['INBOUND', 'RETURN'])
                ->where('reference_no', $validated['ref'])
                ->with('masterSku')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($movement) => ['sku' => $movement->masterSku, 'bin' => $movement->bin_code, 'qty' => $movement->qty]);
        } elseif (!empty($validated['daftar'])) {
            // Daftar dari halaman Thermal Printer: "SKU*jumlah" dipisah koma atau baris baru
            $wanted = [];
            foreach (preg_split('/[\r\n,;]+/', $validated['daftar']) as $entry) {
                if (!preg_match('/^\s*([^\s*]+)(?:(?:\s*[*x×]\s*|\s+)(\d+))?\s*$/iu', $entry, $match)) {
                    continue;
                }
                $code = strtoupper($match[1]);
                $wanted[$code] = ($wanted[$code] ?? 0) + max(1, (int) ($match[2] ?? 1));
            }
            $skus = MasterSku::whereIn('sku', array_keys($wanted))->get()->keyBy(fn ($sku) => strtoupper($sku->sku));
            $lines = collect($wanted)->map(fn ($qty, $code) => ['sku' => $skus[$code] ?? null, 'bin' => null, 'qty' => $qty])->values();
        } elseif (!empty($validated['cari'])) {
            // Semua SKU aktif yang cocok, 1 label per SKU
            $term = '%' . $validated['cari'] . '%';
            $lines = MasterSku::where('status', 'AKTIF')
                ->where(fn ($q) => $q->where('sku', 'like', $term)->orWhere('product_name', 'like', $term)->orWhere('article', 'like', $term))
                ->orderBy('sku')
                ->limit(self::MAX_LABELS + 1)
                ->get()
                ->map(fn ($sku) => ['sku' => $sku, 'bin' => null, 'qty' => 1]);
        } elseif (!empty($validated['sku'])) {
            $lines = collect([[
                'sku' => MasterSku::where('sku', $validated['sku'])->first(),
                'bin' => null,
                'qty' => $validated['qty'] ?? 1,
            ]]);
        }

        $missing = isset($wanted) ? array_keys(array_diff_key($wanted, $skus->all())) : [];
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
            'missing' => $missing,
            'title' => match (true) {
                !empty($validated['ref']) => 'Label SKU ' . $validated['ref'],
                !empty($validated['sku']) => 'Label SKU ' . $validated['sku'],
                default => 'Label QR Master SKU',
            },
        ]);
    }

    /**
     * QR lokasi: label rak (?rak[]=A) beserta semua bin yang pernah dipakai di rak itu,
     * dan/atau kode bin yang diketik (?bin=A-01-01, A-01-01..A-01-10 untuk rentang).
     * ?ukuran=stiker (50×30, default) | sedang (100×50) | besar (100×150) | a4.
     */
    public function bins(Request $request)
    {
        $validated = $request->validate([
            'rak' => ['nullable', 'array'],
            'rak.*' => ['string', 'max:20'],
            'dengan_bin' => ['nullable', 'boolean'],
            'bin' => ['nullable', 'string', 'max:5000'],
            'ukuran' => ['nullable', 'in:' . implode(',', self::QR_SIZES)],
        ]);

        $labels = [];
        $racks = WarehouseRack::whereIn('code', array_map('strtoupper', $validated['rak'] ?? []))->orderBy('code')->pluck('code');
        $usedBins = $racks->isEmpty() || !($validated['dengan_bin'] ?? false) ? collect() : StockActive::query()
            ->distinct()
            ->pluck('bin_code')
            ->map(fn ($bin) => strtoupper($bin))
            ->unique()
            ->sort()
            ->groupBy(fn ($bin) => WarehouseRack::codeFromBin($bin));

        foreach ($racks as $rack) {
            $labels[] = ['code' => $rack, 'title' => 'RAK ' . $rack, 'sub' => 'Scan di Cek Barang untuk melihat isi rak'];
            foreach ($usedBins[$rack] ?? [] as $bin) {
                $labels[] = ['code' => $bin, 'title' => $bin, 'sub' => 'Lokasi bin · rak ' . $rack];
            }
        }

        foreach ($this->expandBins($validated['bin'] ?? '') as $bin) {
            $labels[] = ['code' => $bin, 'title' => $bin, 'sub' => 'Lokasi bin'];
        }

        abort_if(empty($labels), 404, 'Pilih rak atau ketik kode bin yang akan dicetak.');

        return $this->qrLabels($labels, 'Label QR Rak & Bin', $validated['ukuran'] ?? 'stiker');
    }

    /**
     * Lembar QR perintah (Mulai Picking, Mulai Packing, Cek Retur, Tutup Kiriman).
     */
    public function commands(Request $request)
    {
        $validated = $request->validate(['ukuran' => ['nullable', 'in:' . implode(',', self::QR_SIZES)]]);

        $labels = collect(ScanCommand::COMMANDS)
            ->map(fn ($command, $code) => ['code' => $code, 'title' => $command['title'], 'sub' => $command['hint']])
            ->values()
            ->all();

        return $this->qrLabels($labels, 'QR Perintah', $validated['ukuran'] ?? 'besar');
    }

    private function qrLabels(array $labels, string $title, string $size)
    {
        return view('warehouse.label.qr', [
            'labels' => array_slice($labels, 0, self::MAX_LABELS),
            'total' => count($labels),
            'truncated' => count($labels) > self::MAX_LABELS,
            'size' => $size,
            'sizes' => self::QR_SIZES,
            'title' => $title,
        ]);
    }

    /**
     * Kode bin yang diketik: satu per baris/koma, atau rentang "A-01-01..A-01-10"
     * (angka di bagian terakhir dinaikkan satu per satu).
     */
    private function expandBins(string $text): array
    {
        $bins = [];
        foreach (preg_split('/[\r\n,;]+/', strtoupper($text)) as $entry) {
            $entry = preg_replace('/\s+/', '', $entry);
            if ($entry === '') {
                continue;
            }

            if (preg_match('/^(.*?)(\d+)\.\.(.*?)(\d+)$/', $entry, $m) && $m[1] === $m[3] && (int) $m[4] >= (int) $m[2]) {
                $width = strlen($m[2]);
                for ($n = (int) $m[2]; $n <= (int) $m[4] && count($bins) <= self::MAX_LABELS; $n++) {
                    $bins[] = $m[1] . str_pad((string) $n, $width, '0', STR_PAD_LEFT);
                }
                continue;
            }

            $bins[] = $entry;
        }

        return array_values(array_unique($bins));
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

        return $this->shippingLabels(collect([$order]));
    }

    /**
     * Semua label kirim order yang siap dikirim (READY_TO_SHIP) dalam satu kali cetak.
     */
    public function shippingBulk()
    {
        $orders = Order::with('items.masterSku')
            ->where('status', 'READY_TO_SHIP')
            ->orderBy('packed_at')
            ->orderBy('created_at')
            ->get();

        if ($orders->isEmpty()) {
            return redirect()->route('gudang.outbound')->with('error', 'Belum ada order yang siap dikirim.');
        }

        return $this->shippingLabels($orders);
    }

    private function shippingLabels($orders)
    {
        return view('warehouse.label.shipping', [
            'orders' => $orders,
            'senderName' => SystemSetting::get('company_name', config('app.name')),
            'senderPhone' => SystemSetting::get('company_phone'),
            'senderAddress' => SystemSetting::get('company_address'),
        ]);
    }
}
