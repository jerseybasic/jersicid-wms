<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\CourierHandover;
use App\Models\CourierHandoverItem;
use App\Models\Order;
use App\Models\CourierAlias;
use App\Models\SkuAlias;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Serah terima paket ke kurir: pilih kurir, scan resi setiap paket yang diserahkan,
 * lalu semua paket di daftar ditandai Dikirim dan manifestnya dicetak untuk ditandatangani.
 */
class CourierHandoverController extends Controller
{
    /**
     * Serah terima per ekspedisi: paket hasil packing dikelompokkan per ekspedisi dengan
     * statusnya (belum di-scan, sudah di-scan, sudah diserahkan pada tanggal yang dipilih),
     * supaya staf dan kurir bisa mengecek paket mana yang sudah terkirim.
     */
    public function index(Request $request)
    {
        $validated = $request->validate(['tanggal' => ['nullable', 'date']]);
        $date = Carbon::parse($validated['tanggal'] ?? today())->startOfDay();

        $ready = Order::where('status', 'READY_TO_SHIP')
            ->with('handoverItem.handover:id,handover_number,status')
            ->withSum('items', 'qty_ordered')
            ->orderBy('packed_at')
            ->get();

        // Hanya yang benar-benar lewat scan serah terima; sisanya muncul di "perlu dicek" (lihat packedOn)
        $shipped = Order::whereIn('status', ['SHIPPED', 'COMPLETED'])
            ->whereBetween('shipped_at', [$date, $date->copy()->endOfDay()])
            ->whereHas('handoverItem')
            ->with('handoverItem.handover:id,handover_number,driver_name,completed_at')
            ->withSum('items', 'qty_ordered')
            ->orderBy('shipped_at')
            ->get();

        $open = CourierHandover::with('user:id,name')->where('status', 'OPEN')->get()->keyBy('courier');

        // Paket tanpa resi tidak bisa di-scan; ditampilkan terpisah supaya resinya dilengkapi
        [$noResi, $ready] = $ready->partition(fn (Order $order) => blank($order->tracking_number));

        $groups = $ready->concat($shipped)
            ->groupBy(fn (Order $order) => CourierHandover::brand($order->courier))
            ->map(function ($orders, $courier) use ($open) {
                $state = fn (Order $order) => match (true) {
                    $order->status !== 'READY_TO_SHIP' => 'shipped',
                    $order->handoverItem !== null => 'scanned',
                    default => 'waiting',
                };
                $orders = $orders->sortBy(fn ($order) => ['waiting' => 0, 'scanned' => 1, 'shipped' => 2][$state($order)])->values();

                return [
                    'courier' => $courier,
                    'key' => self::key($courier),
                    'open' => $open[$courier] ?? null,
                    'orders' => $orders->map(fn ($order) => ['order' => $order, 'state' => $state($order)]),
                    'counts' => array_merge(['waiting' => 0, 'scanned' => 0, 'shipped' => 0], $orders->countBy($state)->all()),
                ];
            })
            ->sortBy(fn ($group) => [$group['counts']['waiting'] + $group['counts']['scanned'] > 0 ? 0 : 1, $group['courier']])
            ->values();

        $history = CourierHandover::with('user:id,name')->withCount('items')
            ->where('status', 'COMPLETED')->latest('completed_at')->limit(20)->get();

        $packed = self::packedOn($date);

        $canMap = in_array(auth()->user()->role, SkuAlias::MANAGER_ROLES, true);

        return view('warehouse.handover.index', compact('groups', 'noResi', 'history', 'date', 'canMap', 'packed'));
    }

    /**
     * Cocokkan hasil packing dengan serah terima: semua paket yang selesai dikemas pada tanggal ini
     * dan posisinya sekarang. Paket yang tidak lewat scan serah terima (dikirim tanpa scan, dibatalkan,
     * kembali ke picking, dsb.) dikumpulkan di "check" supaya tidak ada paket yang hilang dari pantauan.
     *
     * @return array{total: int, handed: int, waiting: int, check: \Illuminate\Support\Collection}
     */
    public static function packedOn(Carbon $date): array
    {
        $orders = Order::whereHas('packingSession', fn ($q) => $q->where('status', 'COMPLETED')
            ->whereBetween('completed_at', [$date, $date->copy()->endOfDay()]))
            ->with('handoverItem.handover:id,handover_number,status', 'packingSession:id,order_id,completed_at')
            ->get();

        $state = fn (Order $order) => match (true) {
            $order->handoverItem?->handover?->status === 'COMPLETED' => 'handed',
            $order->status === 'READY_TO_SHIP' => 'waiting',
            default => 'check',
        };
        $byState = $orders->groupBy($state);

        return [
            'total' => $orders->count(),
            'handed' => $byState->get('handed', collect())->count(),
            'waiting' => $byState->get('waiting', collect())->count(),
            'check' => $byState->get('check', collect())->sortBy(fn ($order) => $order->packingSession?->completed_at)->values(),
        ];
    }

    /** Alasan paket hasil packing tidak lewat serah terima, untuk daftar "perlu dicek" */
    public static function checkReason(Order $order): string
    {
        return match ($order->status) {
            'SHIPPED', 'COMPLETED' => 'Berstatus ' . $order->status_label . ' tanpa scan serah terima' . ($order->shipped_at ? ' (' . $order->shipped_at->format('d/m H:i') . ')' : ''),
            'CANCELLED' => 'Order dibatalkan setelah dikemas',
            'PICKING', 'PACKING' => 'Kembali ke ' . strtolower($order->status_label) . ' setelah dikemas',
            default => 'Status sekarang: ' . $order->status_label,
        };
    }

    /** Kunci HTML untuk angka per ekspedisi yang diperbarui setelah scan */
    public static function key(string $courier): string
    {
        return Str::slug($courier) ?: 'x';
    }

    public function store(Request $request)
    {
        $validated = $request->validate(['courier' => ['required', 'string', 'max:50']]);
        $courier = $validated['courier'] === CourierHandover::NO_COURIER
            ? CourierHandover::NO_COURIER
            : CourierHandover::brand($validated['courier']);

        // Satu serah terima terbuka per kurir, supaya paket tidak tercecer di dua daftar
        $handover = CourierHandover::where('status', 'OPEN')->where('courier', $courier)->first()
            ?? CourierHandover::create(['courier' => $courier, 'user_id' => auth()->id(), 'status' => 'OPEN']);

        return redirect()->route('gudang.handover.show', $handover);
    }

    public function show(CourierHandover $handover)
    {
        if ($handover->status === 'COMPLETED') {
            return redirect()->route('gudang.handover.print', $handover);
        }

        $orders = CourierHandover::readyOrders($handover->courier, $handover->id);
        $scannedIds = $handover->items()->pluck('order_id')->flip();

        return view('warehouse.handover.show', compact('handover', 'orders', 'scannedIds'));
    }

    /**
     * Scan resi (atau nomor order) paket yang diserahkan ke kurir.
     */
    public function scan(Request $request, CourierHandover $handover)
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:100']]);
        $code = strtoupper(trim($validated['code']));

        try {
            $result = DB::transaction(function () use ($handover, $code) {
                $handover = CourierHandover::lockForUpdate()->findOrFail($handover->id);
                if ($handover->status !== 'OPEN') {
                    throw new \RuntimeException('Serah terima ini sudah selesai.');
                }

                $order = $this->scannableOrder($code);
                if (($brand = CourierHandover::brand($order->courier)) !== $handover->courier) {
                    throw new \RuntimeException("{$order->order_number} untuk kurir {$brand}, bukan {$handover->courier}. Pisahkan paket ini.");
                }

                $count = $this->add($handover, $order);

                return [
                    'ok' => true,
                    'type' => 'order',
                    'message' => "✓ {$order->order_number} · {$order->tracking_number} · " . (int) $order->items_sum_qty_ordered . " pcs. Total {$count} paket.",
                    'items' => [
                        ['id' => 'count', 'qty' => $count],
                        ['id' => 'row-' . $order->id, 'qty' => 'Sudah di-scan'],
                    ],
                ];
            });

            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Scan resi di halaman per ekspedisi: paket langsung masuk ke serah terima ekspedisinya
     * (dibuka otomatis bila belum ada), tanpa harus memilih kurir dulu.
     */
    public function scanAny(Request $request)
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:100']]);
        $code = strtoupper(trim($validated['code']));

        try {
            $result = DB::transaction(function () use ($code) {
                $order = $this->scannableOrder($code);
                $courier = CourierHandover::brand($order->courier);

                $handover = CourierHandover::where('status', 'OPEN')->where('courier', $courier)->lockForUpdate()->first()
                    ?? CourierHandover::create(['courier' => $courier, 'user_id' => auth()->id(), 'status' => 'OPEN']);
                $count = $this->add($handover, $order);
                $waiting = CourierHandover::readyOrders($courier, $handover->id)->filter(fn ($o) => !$o->handoverItem && filled($o->tracking_number))->count();
                $key = self::key($courier);

                return [
                    'ok' => true,
                    'type' => 'order',
                    'message' => "✓ {$courier} · {$order->order_number} · {$order->tracking_number}. {$courier}: {$count} paket di-scan" . ($waiting ? ", {$waiting} belum." : ', semua sudah.'),
                    'items' => [
                        ['id' => 'scanned-' . $key, 'qty' => $count],
                        ['id' => 'waiting-' . $key, 'qty' => $waiting],
                        ['id' => 'pkg-' . $order->id, 'qty' => 'Di-scan', 'class' => 'font-semibold text-cyan-400'],
                    ],
                ];
            });

            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** Order Siap Kirim dengan resi/no. order ini yang boleh diserahkan (dikunci), atau pesan kenapa tidak */
    private function scannableOrder(string $code): Order
    {
        $order = Order::with('handoverItem.handover:id,handover_number,courier')
            ->withSum('items', 'qty_ordered')
            ->where(fn ($q) => $q->whereRaw('UPPER(tracking_number) = ?', [$code])->orWhereRaw('UPPER(order_number) = ?', [$code]))
            ->lockForUpdate()
            ->first();

        if (!$order) {
            throw new \RuntimeException("Resi atau nomor order {$code} tidak ditemukan.");
        }
        if ($order->status === 'SHIPPED' || $order->status === 'COMPLETED') {
            throw new \RuntimeException("{$order->order_number} sudah dikirim sebelumnya" . ($order->shipped_at ? ' (' . $order->shipped_at->format('d/m H:i') . ')' : '') . '.');
        }
        if ($order->status !== 'READY_TO_SHIP') {
            throw new \RuntimeException("{$order->order_number} belum siap kirim (status: {$order->status_label}). Jangan diserahkan.");
        }
        if (blank($order->tracking_number)) {
            throw new \RuntimeException("{$order->order_number} belum punya nomor resi. Cek di BigSeller lalu isi resinya di detail order.");
        }

        return $order;
    }

    /** Masukkan order ke daftar serah terima; mengembalikan jumlah paket di daftar */
    private function add(CourierHandover $handover, Order $order): int
    {
        if ($item = $order->handoverItem) {
            throw new \RuntimeException($item->courier_handover_id === $handover->id
                ? "{$order->order_number} sudah ada di daftar."
                : "{$order->order_number} sudah masuk serah terima {$item->handover?->handover_number}.");
        }

        CourierHandoverItem::create(['courier_handover_id' => $handover->id, 'order_id' => $order->id]);

        return $handover->items()->count();
    }

    /** Keluarkan paket dari daftar (misalnya kurir menolak paketnya) */
    public function removeItem(CourierHandover $handover, Order $order)
    {
        abort_unless($handover->status === 'OPEN', 422, 'Serah terima ini sudah selesai.');
        $handover->items()->where('order_id', $order->id)->delete();

        return back()->with('success', "{$order->order_number} dikeluarkan dari daftar.");
    }

    /**
     * Serahkan: semua paket di daftar menjadi Dikirim, lalu buka manifest untuk dicetak.
     */
    public function finish(Request $request, CourierHandover $handover)
    {
        $validated = $request->validate([
            'driver_name' => ['nullable', 'string', 'max:100'],
            // Tanda tangan kurir dari layar (PNG base64), maksimal sekitar 500 KB
            'signature' => ['nullable', 'string', 'max:700000', 'regex:/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/'],
            'photo' => ['nullable', 'image', 'max:8192'],
        ], [
            'signature.regex' => 'Tanda tangan tidak terbaca. Hapus lalu tanda tangan lagi.',
            'photo.image' => 'Foto harus berupa gambar.',
            'photo.max' => 'Foto maksimal 8 MB.',
        ]);
        if ($handover->status !== 'OPEN') {
            return back()->with('error', 'Serah terima ini sudah selesai.');
        }
        $proof = $this->storeProof($handover, $validated['signature'] ?? null, $request->file('photo'));

        try {
            $count = DB::transaction(function () use ($handover, $validated, $request, $proof) {
                $handover = CourierHandover::lockForUpdate()->findOrFail($handover->id);
                if ($handover->status !== 'OPEN') {
                    throw new \RuntimeException('Serah terima ini sudah selesai.');
                }

                $orders = Order::whereIn('id', $handover->items()->select('order_id'))->lockForUpdate()->get();
                if ($orders->isEmpty()) {
                    throw new \RuntimeException('Belum ada paket yang di-scan.');
                }
                if ($notReady = $orders->where('status', '!=', 'READY_TO_SHIP')->pluck('order_number')->implode(', ')) {
                    throw new \RuntimeException("Status order berubah: {$notReady}. Keluarkan dari daftar dulu.");
                }

                $now = now();
                foreach ($orders as $order) {
                    $order->update(['status' => 'SHIPPED', 'shipped_at' => $now]);
                }
                $handover->update([
                    'status' => 'COMPLETED',
                    'completed_at' => $now,
                    'driver_name' => $validated['driver_name'] ?? null,
                ] + $proof);

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'HANDOVER',
                    'module' => 'OUTBOUND',
                    'new_values' => json_encode([
                        'handover_number' => $handover->handover_number,
                        'courier' => $handover->courier,
                        'orders' => $orders->pluck('order_number'),
                    ]),
                    'ip_address' => $request->ip(),
                ]);

                return $orders->count();
            });
        } catch (\RuntimeException $e) {
            Storage::disk('local')->delete(array_values($proof));

            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('gudang.handover.print', $handover)
            ->with('success', "{$count} paket diserahkan ke {$handover->courier} dan berstatus Dikirim.");
    }

    /**
     * Pemetaan nama jasa kirim ke ekspedisi: setiap nama yang pernah muncul di order
     * beserta ekspedisi tempat paketnya dikelompokkan.
     */
    public function couriers()
    {
        abort_unless(in_array(auth()->user()->role, SkuAlias::MANAGER_ROLES, true), 403);

        $aliases = CourierAlias::pluck('courier', 'name');
        $names = Order::whereNotNull('courier')->where('created_at', '>=', now()->subDays(90))
            ->distinct()->pluck('courier')
            ->map(fn ($name) => CourierHandover::normalizeName($name))
            ->concat($aliases->keys())
            ->filter()->unique()->sort()->values()
            ->map(fn ($name) => ['name' => $name, 'courier' => CourierHandover::brand($name), 'custom' => isset($aliases[$name])]);

        return view('warehouse.handover.couriers', [
            'names' => $names,
            'brands' => $names->pluck('courier')->concat(array_values(CourierHandover::DEFAULT_BRANDS))->unique()->sort()->values(),
        ]);
    }

    public function saveCouriers(Request $request)
    {
        abort_unless(in_array(auth()->user()->role, SkuAlias::MANAGER_ROLES, true), 403);

        $validated = $request->validate([
            'map' => ['required', 'array'],
            'map.*.name' => ['required', 'string', 'max:100'],
            'map.*.courier' => ['nullable', 'string', 'max:50'],
        ]);

        $changed = DB::transaction(function () use ($validated, $request) {
            $changed = [];
            foreach ($validated['map'] as ['name' => $name, 'courier' => $courier]) {
                $name = CourierHandover::normalizeName($name);
                $courier = CourierHandover::normalizeName($courier);
                $before = CourierHandover::brand($name);

                // Kosong atau sama dengan aturan bawaan: cukup pakai aturan bawaan
                CourierAlias::where('name', $name)->first()?->delete();
                if ($courier !== '' && $courier !== CourierHandover::brand($name)) {
                    CourierAlias::create(['name' => $name, 'courier' => $courier]);
                }

                if (($after = CourierHandover::brand($name)) !== $before) {
                    $changed[$name] = $after;
                }
            }

            if ($changed) {
                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'UPDATE',
                    'module' => 'EKSPEDISI',
                    'new_values' => json_encode($changed),
                    'ip_address' => $request->ip(),
                ]);
            }

            return $changed;
        });

        return redirect()->route('gudang.handover.couriers')
            ->with('success', $changed ? count($changed) . ' nama jasa kirim dipetakan ulang.' : 'Tidak ada yang berubah.');
    }

    /** Simpan tanda tangan dan foto bukti serah terima di disk privat */
    private function storeProof(CourierHandover $handover, ?string $signature, ?UploadedFile $photo): array
    {
        $proof = [];
        $dir = 'serah-terima/' . $handover->id;
        if ($signature) {
            $path = "{$dir}/tanda-tangan-" . now()->format('YmdHis') . '.png';
            Storage::disk('local')->put($path, base64_decode(substr($signature, strpos($signature, ',') + 1)));
            $proof['signature_path'] = $path;
        }
        if ($photo) {
            $proof['photo_path'] = $photo->storeAs($dir, 'foto-' . now()->format('YmdHis') . '.' . ($photo->extension() ?: 'jpg'), 'local');
        }

        return $proof;
    }

    /** Tampilkan tanda tangan atau foto bukti serah terima */
    public function proof(CourierHandover $handover, string $type)
    {
        $path = match ($type) {
            'tanda-tangan' => $handover->signature_path,
            'foto' => $handover->photo_path,
            default => null,
        };
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    public function print(CourierHandover $handover)
    {
        $handover->load(['user:id,name', 'orders' => fn ($q) => $q->withSum('items', 'qty_ordered')->orderBy('order_number')]);

        return view('warehouse.handover.print', compact('handover'));
    }

    /** Batalkan serah terima yang belum diserahkan. Status paket tidak berubah. */
    public function destroy(CourierHandover $handover)
    {
        abort_unless($handover->status === 'OPEN', 422, 'Serah terima yang sudah selesai tidak bisa dibatalkan.');
        $handover->delete();

        return redirect()->route('gudang.handover')->with('success', "Serah terima {$handover->handover_number} dibatalkan. Paketnya tetap Siap Kirim.");
    }
}
