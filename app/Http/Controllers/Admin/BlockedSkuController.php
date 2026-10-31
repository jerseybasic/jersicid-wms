<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\MasterSku;
use App\Models\SkuAlias;
use App\Models\StockActive;
use App\Models\UnregisteredSku;
use App\Models\WarehouseRack;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SKU terhambat (Master Data → Pemetaan SKU → SKU Terhambat):
 * 1. Belum terdaftar: kode yang muncul saat import pesanan tetapi tidak ada di Master SKU dan belum dipetakan.
 * 2. Belum punya rak: SKU aktif yang belum ditetapkan ke rak dan stoknya tidak ada di lokasi rak mana pun.
 */
class BlockedSkuController extends Controller
{
    /** Role yang boleh membuat SKU baru dari halaman ini (sama dengan Master SKU) */
    public const CREATE_SKU_ROLES = ['OWNER', 'SUPERVISOR'];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('q'));

        $unregistered = UnregisteredSku::pending()
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('product_name', 'like', "%{$search}%")))
            ->orderByDesc('orders_count')
            ->orderByDesc('last_seen_at')
            ->get();

        $unplaced = $this->unplaced($search);

        $skuOptions = $unregistered->isNotEmpty()
            ? MasterSku::where('status', 'AKTIF')->orderBy('sku')->get(['sku', 'product_name', 'size'])
            : collect();

        return view('admin.sku-aliases.blocked', [
            'unregistered' => $unregistered,
            'unplaced' => $unplaced,
            'search' => $search,
            'skuOptions' => $skuOptions,
            'canCreateSku' => in_array(auth()->user()->role, self::CREATE_SKU_ROLES, true),
        ]);
    }

    /** Petakan kode yang belum terdaftar ke SKU gudang yang sudah ada */
    public function map(Request $request)
    {
        $validated = $request->validate([
            'alias' => ['required', 'string', 'max:100'],
            'sku' => ['required', 'string', 'max:255'],
        ], [
            'sku.required' => 'Pilih SKU gudang yang sama dengan kode ini.',
        ]);

        // Pilihan dari daftar berbentuk "SKU — nama produk"; ambil kodenya saja
        $sku = trim(explode(' — ', $validated['sku'])[0]);
        if ($error = SkuAlias::map($validated['alias'], $sku, auth()->id())) {
            return back()->with('error', $error);
        }

        $alias = SkuAlias::normalize($validated['alias']);
        $this->audit($request, 'CREATE', 'SKU_ALIAS', ['alias' => $alias, 'sku' => SkuAlias::normalize($sku)]);

        return back()->with('success', "{$alias} sekarang dibaca sebagai " . SkuAlias::normalize($sku) . '. Upload ulang file pesanannya supaya pesanan yang tertahan ikut diimpor.');
    }

    /** Sembunyikan kode yang memang bukan produk gudang (mis. ongkir, bonus). Muncul lagi jika ditemui lagi. */
    public function ignore(Request $request, UnregisteredSku $unregisteredSku)
    {
        $unregisteredSku->update(['ignored_at' => now(), 'ignored_by' => auth()->id()]);
        $this->audit($request, 'IGNORE', 'SKU_ALIAS', ['code' => $unregisteredSku->code]);

        return back()->with('success', "{$unregisteredSku->code} disembunyikan dari daftar. Akan muncul lagi jika ditemui di import berikutnya.");
    }

    /**
     * SKU aktif yang belum punya lokasi rak: tidak ditetapkan ke rak mana pun dan tidak ada stok di bin rak yang dikenal.
     */
    private function unplaced(string $search): Collection
    {
        $rackCodes = WarehouseRack::pluck('code')->flip();
        $assigned = DB::table('rack_products')->distinct()->pluck('sku')->flip();

        $stockBySku = StockActive::where('qty', '>', 0)->get(['sku', 'bin_code', 'qty'])->groupBy('sku');

        return MasterSku::where('status', 'AKTIF')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('product_name', 'like', "%{$search}%")))
            ->orderBy('sku')
            ->get(['sku', 'product_name', 'size', 'created_at'])
            ->reject(function ($sku) use ($assigned, $stockBySku, $rackCodes) {
                if (isset($assigned[$sku->sku])) {
                    return true;
                }

                return ($stockBySku[$sku->sku] ?? collect())
                    ->contains(fn ($stock) => isset($rackCodes[WarehouseRack::codeFromBin($stock->bin_code)]));
            })
            ->map(function ($sku) use ($stockBySku) {
                $stocks = $stockBySku[$sku->sku] ?? collect();
                $sku->stock = (int) $stocks->sum('qty');
                $sku->bins = $stocks->pluck('bin_code')->unique()->values()->all();

                return $sku;
            })
            // Yang sudah ada stoknya (barang fisik tanpa rak) lebih mendesak
            ->sortByDesc('stock')
            ->values();
    }

    private function audit(Request $request, string $action, string $module, array $new): void
    {
        AuditTrail::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'module' => $module,
            'new_values' => json_encode($new),
            'ip_address' => $request->ip(),
        ]);
    }
}
