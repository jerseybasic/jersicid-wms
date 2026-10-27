<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\StockActive;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\StockMovement;
use App\Models\AuditTrail;
use App\Models\Warehouse;
use App\Models\WarehouseRack;
use Illuminate\Http\Request;
use App\Services\WaitingStock;
use Illuminate\Support\Facades\DB;

class StockOpnameController extends Controller
{
    /** Opname yang masih terbuka: raknya tidak boleh ikut opname lain */
    private const OPEN_STATUSES = ['DRAFT', 'IN_PROGRESS', 'SUBMITTED'];

    /** Yang boleh menyetujui opname (stok baru disesuaikan setelah disetujui) */
    public const MANAGER_ROLES = ['OWNER', 'SUPERVISOR'];

    public function index(Request $request)
    {
        $opnames = StockOpname::with(['creator:id,name', 'warehouse:id,name'])
            ->withCount('items')
            ->withCount(['items as counted_count' => fn ($q) => $q->whereNotNull('physical_qty')])
            ->withCount(['items as diff_count' => fn ($q) => $q->whereNotNull('physical_qty')->where('difference', '!=', 0)])
            ->when($request->filled('search'), fn ($q) => $q->where('opname_number', 'like', "%{$request->search}%"))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderBy('created_at', 'desc')
            ->paginate(15)
            ->withQueryString();

        return view('warehouse.opname.index', compact('opnames'));
    }

    public function create()
    {
        $warehouses = Warehouse::where('status', 'AKTIF')->get();
        $busyRacks = $this->racksInOpenOpname();

        // Rak dari denah + rak yang muncul di lokasi stok, beserta jumlah lokasi berisi barang
        $binsPerRack = StockActive::where('qty', '>', 0)->pluck('bin_code')
            ->groupBy(fn ($bin) => WarehouseRack::codeFromBin($bin))
            ->map->count();
        $racks = WarehouseRack::orderBy('code')->pluck('code')
            ->merge($binsPerRack->keys())
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->map(fn ($code) => [
                'code' => $code,
                'bins' => $binsPerRack[$code] ?? 0,
                'busy' => in_array($code, $busyRacks) || in_array('*', $busyRacks),
            ]);

        return view('warehouse.opname.create', compact('warehouses', 'racks', 'busyRacks'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'racks' => ['nullable', 'array'],
            'racks.*' => ['string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $racks = collect($validated['racks'] ?? [])->map(fn ($r) => strtoupper(trim($r)))->filter()->unique()->sort()->values()->all();

        // Satu rak hanya boleh dihitung oleh satu opname yang masih terbuka
        $busy = $this->racksInOpenOpname();
        $clash = in_array('*', $busy) ? ($racks ?: ['*']) : ($racks ? array_intersect($racks, $busy) : $busy);
        if ($clash) {
            $label = in_array('*', $clash) ? 'seluruh gudang' : 'rak ' . implode(', ', $clash);
            return back()->withInput()->with('error', "Masih ada opname yang belum selesai untuk {$label}. Selesaikan atau hapus opname itu dulu.");
        }

        $stocks = StockActive::where('qty', '>', 0)->orderBy('bin_code')->orderBy('sku')->get();
        if ($racks) {
            $stocks = $stocks->filter(fn ($stock) => in_array(WarehouseRack::codeFromBin($stock->bin_code), $racks));
        }

        if ($stocks->isEmpty()) {
            return back()->withInput()->with('error', 'Tidak ada stok di rak yang dipilih.');
        }

        try {
            $opname = DB::transaction(function () use ($validated, $racks, $stocks) {
                $opname = StockOpname::create([
                    'warehouse_id' => $validated['warehouse_id'],
                    'rack_codes' => $racks ? implode(',', $racks) : null,
                    'status' => 'DRAFT',
                    'created_by' => auth()->id(),
                    'notes' => $validated['notes'] ?? null,
                ]);

                foreach ($stocks as $stock) {
                    StockOpnameItem::create([
                        'stock_opname_id' => $opname->id,
                        'sku' => $stock->sku,
                        'bin_code' => $stock->bin_code,
                        'system_qty' => $stock->qty,
                        'physical_qty' => null,
                        'difference' => 0,
                    ]);
                }

                return $opname;
            });

            return redirect()->route('gudang.opname.show', $opname->id)
                ->with('success', "Opname {$opname->opname_number} dibuat ({$stocks->count()} lokasi). Klik Mulai Opname untuk mulai menghitung.");
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Gagal membuat Stock Opname: ' . safe_error_message($e));
        }
    }

    public function show(Request $request, $id)
    {
        $opname = StockOpname::with(['creator:id,name', 'submitter:id,name', 'approver:id,name', 'warehouse:id,name'])->findOrFail($id);

        // Ringkasan dihitung dari semua item, bukan hanya halaman yang tampil
        $summary = [
            'total' => $opname->items()->count(),
            'counted' => $opname->items()->whereNotNull('physical_qty')->count(),
            'diff' => $opname->items()->whereNotNull('physical_qty')->where('difference', '!=', 0)->count(),
        ];

        // Pilihan rak untuk menyaring daftar, supaya staf bisa menghitung rak per rak
        $rackFilter = strtoupper((string) $request->query('rak', ''));
        $racksInOpname = $opname->items()->distinct()->pluck('bin_code')
            ->map(fn ($bin) => WarehouseRack::codeFromBin($bin))->unique()->sort()->values();

        $items = $opname->items()
            ->with('masterSku:sku,product_name,size')
            ->when($rackFilter !== '', function ($q) use ($opname, $rackFilter) {
                $bins = $opname->items()->distinct()->pluck('bin_code')
                    ->filter(fn ($bin) => WarehouseRack::codeFromBin($bin) === $rackFilter);
                $q->whereIn('bin_code', $bins);
            })
            ->orderBy('bin_code')
            ->orderBy('sku')
            ->paginate(50)
            ->withQueryString();

        return view('warehouse.opname.show', compact('opname', 'items', 'summary', 'racksInOpname', 'rackFilter'));
    }

    /**
     * Barang ditemukan di rak tapi tidak ada di daftar opname: tambahkan dengan stok sistem saat ini (biasanya 0).
     */
    public function addItem(Request $request, $id)
    {
        $opname = StockOpname::findOrFail($id);
        if ($opname->status !== 'IN_PROGRESS') {
            return back()->with('error', 'Barang hanya bisa ditambahkan saat opname sedang dihitung.');
        }

        $validated = $request->validate([
            'sku' => ['required', 'exists:master_skus,sku'],
            'bin_code' => ['required', 'string', 'max:50'],
        ], [
            'sku.exists' => 'SKU tidak terdaftar di Master SKU.',
        ]);
        $bin = strtoupper(trim($validated['bin_code']));

        if ($opname->racks && !in_array(WarehouseRack::codeFromBin($bin), $opname->racks)) {
            return back()->with('error', "Lokasi {$bin} di luar rak opname ini ({$opname->scope_label}).");
        }

        if ($opname->items()->where('sku', $validated['sku'])->where('bin_code', $bin)->exists()) {
            return back()->with('error', "{$validated['sku']} di {$bin} sudah ada di daftar.");
        }

        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'sku' => $validated['sku'],
            'bin_code' => $bin,
            'system_qty' => (int) StockActive::where('sku', $validated['sku'])->where('bin_code', $bin)->value('qty'),
            'physical_qty' => null,
            'difference' => 0,
        ]);

        return redirect()->route('gudang.opname.show', ['id' => $opname->id, 'rak' => WarehouseRack::codeFromBin($bin)])
            ->with('success', "{$validated['sku']} di {$bin} ditambahkan. Isi stok fisiknya.");
    }

    /**
     * Rak yang sedang dihitung opname lain yang belum selesai. '*' = ada opname seluruh gudang.
     */
    private function racksInOpenOpname(): array
    {
        return StockOpname::whereIn('status', self::OPEN_STATUSES)->get(['rack_codes'])
            ->flatMap(fn ($o) => $o->rack_codes ? explode(',', $o->rack_codes) : ['*'])
            ->unique()
            ->values()
            ->all();
    }

    public function start($id)
    {
        $opname = StockOpname::findOrFail($id);
        if ($opname->status !== 'DRAFT') {
            return back()->with('error', 'Hanya opname DRAFT yang bisa dimulai.');
        }

        $opname->update(['status' => 'IN_PROGRESS', 'started_at' => now()]);
        return back()->with('success', 'Opname dimulai. Silakan input stok fisik.');
    }

    public function updatePhysicalQty(Request $request, $id)
    {
        $opname = StockOpname::findOrFail($id);
        if ($opname->status !== 'IN_PROGRESS') {
            return back()->with('error', 'Stok fisik hanya bisa diisi saat opname sedang dihitung.');
        }

        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'exists:stock_opname_items,id'],
            'items.*.physical_qty' => ['required', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            DB::transaction(function () use ($validated, $opname) {
                foreach ($validated['items'] as $itemData) {
                    $item = $opname->items()->findOrFail($itemData['id']);
                    $item->physical_qty = $itemData['physical_qty'];
                    $item->notes = $itemData['notes'] ?? null;
                    $item->calculateDifference();
                    $item->save();
                }
            });
            return back()->with('success', 'Data stok fisik berhasil disimpan sementara.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menyimpan data: ' . safe_error_message($e));
        }
    }

    /**
     * Selesai menghitung. Owner/Supervisor langsung menyesuaikan stok; role lain mengirim opname
     * untuk disetujui dulu, dan stok baru berubah setelah Owner/Supervisor menyetujui.
     */
    public function complete($id)
    {
        $opname = StockOpname::findOrFail($id);
        $user = auth()->user();

        if ($opname->status !== 'IN_PROGRESS') {
            return back()->with('error', 'Hanya opname yang sedang dihitung yang bisa diselesaikan.');
        }

        $unfilled = $opname->items()->whereNull('physical_qty')->count();
        if ($unfilled > 0) {
            return back()->with('error', "Masih ada {$unfilled} item yang belum dihitung fisiknya.");
        }

        if (!$this->isManager()) {
            $opname->update(['status' => 'SUBMITTED', 'submitted_by' => $user->id, 'submitted_at' => now(), 'rejection_note' => null]);
            $this->audit('SUBMIT', $opname, ['diff_items' => $opname->items()->where('difference', '!=', 0)->count()]);

            return back()->with('success', 'Opname dikirim untuk disetujui. Stok sistem belum berubah sampai Owner/Supervisor menyetujui.');
        }

        return $this->applyAdjustments($opname, 'Stock Opname selesai. Stok sistem sudah disesuaikan.');
    }

    /** Owner/Supervisor menyetujui opname yang dikirim staf: stok disesuaikan sekarang */
    public function approve($id)
    {
        abort_unless($this->isManager(), 403, 'Hanya Owner atau Supervisor yang bisa menyetujui opname.');
        $opname = StockOpname::findOrFail($id);

        if ($opname->status !== 'SUBMITTED') {
            return back()->with('error', 'Opname ini tidak sedang menunggu persetujuan.');
        }

        return $this->applyAdjustments($opname, 'Opname disetujui. Stok sistem sudah disesuaikan.');
    }

    /** Owner/Supervisor menolak: opname kembali ke staf untuk dihitung ulang, stok tidak berubah */
    public function reject(Request $request, $id)
    {
        abort_unless($this->isManager(), 403, 'Hanya Owner atau Supervisor yang bisa menolak opname.');
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Tulis alasan supaya staf tahu apa yang perlu dihitung ulang.',
        ]);
        $opname = StockOpname::findOrFail($id);

        if ($opname->status !== 'SUBMITTED') {
            return back()->with('error', 'Opname ini tidak sedang menunggu persetujuan.');
        }

        $opname->update(['status' => 'IN_PROGRESS', 'rejection_note' => $validated['reason']]);
        $this->audit('REJECT', $opname, ['reason' => $validated['reason']]);

        return back()->with('success', 'Opname dikembalikan ke staf untuk dihitung ulang. Stok sistem tidak berubah.');
    }

    private function applyAdjustments(StockOpname $opname, string $message)
    {
        $user = auth()->user();

        try {
            DB::transaction(function () use ($opname, $user) {
                $opname = StockOpname::lockForUpdate()->findOrFail($opname->id);
                if (!in_array($opname->status, ['IN_PROGRESS', 'SUBMITTED'], true)) {
                    throw new \RuntimeException('Opname ini sudah diproses.');
                }

                foreach ($opname->items as $item) {
                    $diff = $item->physical_qty - $item->system_qty;

                    if ($diff !== 0) {
                        // Barang yang ditemukan di lokasi baru belum punya baris stok
                        $stock = StockActive::firstOrCreate(
                            ['sku' => $item->sku, 'bin_code' => $item->bin_code],
                            ['qty' => 0, 'reserved_qty' => 0]
                        );
                        $stock = StockActive::lockForUpdate()->find($stock->id);

                        $oldQty = $stock->qty;
                        $newQty = max(0, $oldQty + $diff);

                        $stock->update([
                            'qty' => $newQty,
                            'last_movement_at' => now(),
                        ]);

                        StockMovement::create([
                            'user_id' => $user->id,
                            'sku' => $item->sku,
                            'bin_code' => $item->bin_code,
                            'type' => 'ADJUSTMENT',
                            'qty' => abs($diff),
                            'qty_before' => $oldQty,
                            'qty_after' => $newQty,
                            'reference_no' => $opname->opname_number,
                            'notes' => "Opname Adj: {$diff} (Sys: {$item->system_qty}, Phy: {$item->physical_qty})",
                            'ip_address' => request()->ip(),
                        ]);
                    }
                }

                $opname->update([
                    'status' => 'COMPLETED',
                    'completed_at' => now(),
                    'approved_by' => $user->id,
                    'rejection_note' => null,
                ]);
                $this->audit('APPROVE', $opname, ['diff_items' => $opname->items()->where('difference', '!=', 0)->count()]);
            });

            $released = WaitingStock::release($user->id, request()->ip());

            return back()->with('success', $message . WaitingStock::releasedMessage($released));
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menyelesaikan opname: ' . safe_error_message($e));
        }
    }

    private function isManager(): bool
    {
        return in_array(auth()->user()->role, self::MANAGER_ROLES, true);
    }

    private function audit(string $action, StockOpname $opname, array $values = []): void
    {
        AuditTrail::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'module' => 'STOCK_OPNAME',
            'new_values' => json_encode(['opname_number' => $opname->opname_number, 'scope' => $opname->scope_label] + $values),
            'ip_address' => request()->ip(),
        ]);
    }

    public function destroy($id)
    {
        $opname = StockOpname::findOrFail($id);
        if ($opname->status !== 'DRAFT') {
            return back()->with('error', 'Hanya DRAFT yang bisa dihapus.');
        }
        $opname->items()->delete();
        $opname->delete();
        return back()->with('success', 'Opname dihapus.');
    }
}
