<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\Order;
use App\Services\ResiShipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Input resi yang sudah dikirim lewat BigSeller: tempel daftar resi → cek → proses.
 * Stok gudang dipotong tanpa picking, supaya tetap sama dengan barang di rak (lihat ResiShipment).
 */
class ResiShipmentController extends Controller
{
    private function authorizeRole(): void
    {
        abort_unless(in_array(auth()->user()->role, ResiShipment::ROLES, true), 403, 'Input resi BigSeller hanya untuk Owner, Supervisor, dan Admin Gudang.');
    }

    private function codesFrom(Request $request): array
    {
        $request->validate(['resi' => ['required', 'string', 'max:20000']], [
            'resi.required' => 'Tempel dulu daftar resinya.',
        ]);

        return ResiShipment::parse($request->input('resi'));
    }

    public function create()
    {
        $this->authorizeRole();

        return view('warehouse.outbound.resi', ['text' => old('resi', '')]);
    }

    public function preview(Request $request)
    {
        $this->authorizeRole();
        ['codes' => $codes, 'repeated' => $repeated] = $this->codesFrom($request);
        if (!$codes) {
            return back()->withInput()->with('error', 'Tidak ada resi yang terbaca.');
        }
        if (count($codes) > ResiShipment::MAX_CODES) {
            return back()->withInput()->with('error', 'Paling banyak ' . ResiShipment::MAX_CODES . ' resi sekali proses. Bagi jadi beberapa kali.');
        }

        $rows = ResiShipment::check($codes);

        return view('warehouse.outbound.resi-preview', [
            'rows' => $rows,
            'repeated' => $repeated,
            'counts' => collect($rows)->countBy('result')->all(),
            'text' => implode("\n", $codes),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeRole();
        ['codes' => $codes] = $this->codesFrom($request);
        $codes = array_slice($codes, 0, ResiShipment::MAX_CODES);

        $shipped = [];
        $marked = [];
        $failed = [];
        $missing = [];
        // Diperiksa ulang saat menyimpan: bisa saja ada yang berubah sejak pratinjau
        foreach (ResiShipment::check($codes) as $row) {
            if ($row['result'] === 'missing') {
                $missing[] = $row['code'];
                continue;
            }
            if ($row['result'] === 'skip') {
                continue;
            }
            try {
                $result = DB::transaction(fn () => ResiShipment::ship($row['order'], $row['code'], auth()->id(), $request->ip()));
                if ($result === 'ship') {
                    $shipped[] = $row['order']->order_number;
                } else {
                    $marked[] = $row['order']->order_number;
                }
            } catch (\RuntimeException $e) {
                $failed[] = $e->getMessage();
            }
        }

        if ($shipped || $marked || $missing) {
            AuditTrail::record('RESI_BIGSELLER', 'OUTBOUND', array_filter([
                'resi' => count($codes),
                'stock_cut' => $shipped,
                'marked_shipped' => $marked,
                'not_found' => $missing,
                'failed' => count($failed),
            ]));
        }

        $done = count($shipped) + count($marked);
        $message = $done
            ? "{$done} order ditandai dikirim" . ($shipped ? ', stok ' . count($shipped) . ' order dipotong dari rak' : '') . '.'
            : 'Tidak ada order yang diproses.';
        if ($missing) {
            $message .= ' ' . count($missing) . ' resi tidak ditemukan: ' . implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ', ...' : '') . '. Import pesanannya dari BigSeller dulu, lalu input lagi resinya.';
        }

        return redirect()->route('gudang.outbound.resi')
            ->with($done ? 'success' : 'error', $message)
            ->with('resi_failed', $failed)
            ->with('resi_missing', $missing);
    }
}
