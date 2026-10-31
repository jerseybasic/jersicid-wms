<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\MasterSku;
use App\Models\OrderItem;
use App\Models\SkuAlias;
use App\Models\UnregisteredSku;
use App\Http\Controllers\Admin\BlockedSkuController;
use App\Services\BigSellerOrderImport;
use App\Services\ShipDeadline;
use App\Services\StockAvailability;
use Carbon\Carbon;
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
        $fileName = $request->file('file')->getClientOriginalName();
        $this->recordUnregistered($orders, $fileName);
        Cache::put($this->cacheKey($token), compact('orders', 'fileName'), now()->addMinutes(self::CACHE_MINUTES));

        return $this->previewView($orders, $token, $fileName);
    }

    /**
     * Simpan pemetaan SKU marketplace yang diisi di pratinjau, lalu periksa ulang pesanan tanpa upload ulang.
     */
    public function map(Request $request, BigSellerOrderImport $import)
    {
        abort_unless(in_array(auth()->user()->role, SkuAlias::MANAGER_ROLES, true), 403, 'Pemetaan SKU hanya untuk Owner, Supervisor, dan Admin Gudang.');

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'map' => ['nullable', 'array'],
            'map.*.alias' => ['required', 'string', 'max:255'],
            'map.*.sku' => ['nullable', 'string', 'max:255'],
        ]);

        $cached = Cache::get($this->cacheKey($validated['token']));
        if (!$cached) {
            return redirect()->route('gudang.outbound.import')
                ->with('error', 'Pratinjau sudah kedaluwarsa. Upload ulang filenya.');
        }

        $saved = [];
        $errors = [];
        foreach ($validated['map'] ?? [] as ['alias' => $alias, 'sku' => $sku]) {
            if (blank($sku)) {
                continue;
            }
            // Pilihan dari daftar berbentuk "SKU — nama produk"; ambil kodenya saja
            $sku = trim(explode(' — ', $sku)[0]);
            if ($error = SkuAlias::map($alias, $sku, auth()->id())) {
                $errors[] = $error;
            } else {
                $saved[SkuAlias::normalize($alias)] = SkuAlias::normalize($sku);
            }
        }

        if ($saved) {
            AuditTrail::create([
                'user_id' => auth()->id(),
                'action' => 'CREATE',
                'module' => 'SKU_ALIAS',
                'new_values' => json_encode($saved),
                'ip_address' => $request->ip(),
            ]);
        }

        $orders = $import->check($cached['orders']);
        Cache::put($this->cacheKey($validated['token']), ['orders' => $orders, 'fileName' => $cached['fileName']], now()->addMinutes(self::CACHE_MINUTES));

        return $this->previewView($orders, $validated['token'], $cached['fileName'], [
            'success' => $saved ? count($saved) . ' SKU berhasil dipetakan. Pratinjau sudah diperbarui.' : null,
            'error' => $errors ? implode(' ', $errors) : null,
        ]);
    }

    /**
     * Kembali ke pratinjau yang sama (mis. setelah membuat SKU baru), diperiksa ulang tanpa upload ulang.
     */
    public function show(string $token, BigSellerOrderImport $import)
    {
        $cached = Cache::get($this->cacheKey($token));
        if (!$cached) {
            return redirect()->route('gudang.outbound.import')
                ->with('error', 'Pratinjau sudah kedaluwarsa. Upload ulang filenya.');
        }

        $orders = $import->check($cached['orders']);
        Cache::put($this->cacheKey($token), ['orders' => $orders, 'fileName' => $cached['fileName']], now()->addMinutes(self::CACHE_MINUTES));

        return $this->previewView($orders, $token, $cached['fileName'], [
            'success' => session('success'),
            'error' => session('error'),
        ]);
    }

    /** SKU yang belum ada di Master SKU dicatat supaya tampil di daftar SKU Terhambat */
    private function recordUnregistered(array $orders, string $fileName): void
    {
        collect($orders)
            ->flatMap(fn ($order) => collect($order['items'])->where('known', false)
                ->map(fn ($item) => $item + ['order' => $order['order_number']]))
            ->groupBy('sku')
            ->each(fn ($items, $sku) => UnregisteredSku::record(
                (string) $sku,
                $items->first()['name'] ?? null,
                'IMPORT_BIGSELLER',
                $fileName,
                $items->pluck('order')->unique()->count(),
            ));
    }

    private function previewView(array $orders, string $token, string $fileName, array $flash = [])
    {
        // SKU yang belum dikenal, dengan nama produknya dari file, untuk dipetakan di pratinjau
        $unknown = collect($orders)->pluck('items')->flatten(1)->where('known', false)
            ->unique('sku')->sortBy('sku')->values();
        $skuOptions = $unknown->isNotEmpty()
            ? MasterSku::where('status', 'AKTIF')->orderBy('sku')->get(['sku', 'product_name', 'size'])
            : collect();

        // Tebakan awal: SKU gudang yang sama jika tanda baca dan spasi diabaikan (JER GOTHIC_M = JER-GOTHIC-M)
        $plain = fn ($code) => preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
        $byPlain = $skuOptions->groupBy(fn ($sku) => $plain($sku->sku))->filter(fn ($group) => $group->count() === 1);
        $unknown = $unknown->map(fn ($item) => $item + ['suggest' => $byPlain->get($plain($item['sku']))?->first()?->sku]);

        return view('warehouse.outbound.import-preview', [
            'orders' => $orders,
            'token' => $token,
            'fileName' => $fileName,
            'flash' => $flash,
            'unknown' => $unknown,
            'canMap' => in_array(auth()->user()->role, SkuAlias::MANAGER_ROLES, true),
            'canCreateSku' => in_array(auth()->user()->role, BlockedSkuController::CREATE_SKU_ROLES, true),
            'skuOptions' => $skuOptions,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'confirm_now' => ['nullable', 'boolean'],
        ]);

        $orders = Cache::pull($this->cacheKey($validated['token']))['orders'] ?? null;
        if (!$orders) {
            return redirect()->route('gudang.outbound.import')
                ->with('error', 'Pratinjau sudah kedaluwarsa. Upload ulang filenya.');
        }

        $confirmNow = $request->boolean('confirm_now');
        $created = [];
        $confirmed = 0;
        $skipped = 0;

        DB::transaction(function () use ($orders, $confirmNow, $request, &$created, &$confirmed, &$skipped) {
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
                    'status' => 'PENDING',
                    'notes' => $this->notes($data),
                    'ship_by' => $this->shipBy($data),
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

                // Langsung dikonfirmasi hanya jika stoknya cukup; sisanya tetap Pending di Outbound
                if ($confirmNow && !StockAvailability::shortages($order)) {
                    $order->update(['status' => 'CONFIRMED']);
                    $confirmed++;
                }

                $created[] = $order->order_number;
            }

            AuditTrail::create([
                'user_id' => auth()->id(),
                'action' => 'IMPORT',
                'module' => 'OUTBOUND',
                'new_values' => json_encode(['source' => 'BigSeller', 'confirmed' => $confirmed, 'orders' => $created]),
                'ip_address' => $request->ip(),
            ]);
        });

        $message = count($created) . ' pesanan berhasil diimpor';
        if ($confirmNow && $confirmed === count($created)) {
            $message .= ' dan langsung masuk daftar picking.';
        } elseif ($confirmNow && $confirmed === 0) {
            $message .= ', tetapi semuanya tetap Pending karena stoknya kurang.';
        } elseif ($confirmNow) {
            $pending = count($created) - $confirmed;
            $message .= ". {$confirmed} langsung masuk daftar picking, {$pending} tetap Pending karena stoknya kurang.";
        } else {
            $message .= '.';
        }
        if ($skipped) {
            $message .= " {$skipped} pesanan dilewati.";
        }

        return redirect()->route('gudang.outbound')->with(count($created) ? 'success' : 'error', $message);
    }

    /** Batas kirim dari file; jika tidak ada, dihitung dari waktu pesanan dibuat di marketplace */
    private function shipBy(array $data): ?Carbon
    {
        if ($deadline = ShipDeadline::parse($data['ship_by'] ?? null)) {
            return $deadline;
        }
        $orderedAt = ShipDeadline::parse($data['ordered_at'] ?? null);

        return $orderedAt ? ShipDeadline::forOrderedAt($orderedAt) : null;
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
