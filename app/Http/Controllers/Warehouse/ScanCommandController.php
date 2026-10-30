<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\PackingBatch;
use App\Models\PackingSession;
use App\Models\PickingBatch;
use App\Models\PickingSession;
use App\Services\Packing;
use App\Services\Picking;
use App\Services\ScanCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Menjalankan QR perintah yang di-scan di kotak scan mana pun.
 * Jawaban {ok, message, redirect}: kotak scan lalu membuka halaman redirect.
 */
class ScanCommandController extends Controller
{
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:100']]);

        return match (ScanCommand::parse($validated['code'])) {
            'CMD-PICKING' => $this->startPicking($request),
            'CMD-PACKING' => $this->startPacking($request),
            'CMD-RETUR' => $this->open('Membuka cek retur...', route('gudang.retur.create')),
            'CMD-TUTUP' => $this->open('Membuka tutup kiriman hari ini...', route('gudang.closing')),
            default => ($order = Order::findByCode($validated['code']))
                ? $this->openOrder($order, $request)
                : response()->json([
                    'ok' => false,
                    'message' => 'Ini bukan QR perintah atau nomor order. Scan barang di layar picking, packing, atau cek barang.',
                ], 422),
        };
    }

    private function open(string $message, string $url): JsonResponse
    {
        return response()->json(['ok' => true, 'type' => 'command', 'message' => $message, 'redirect' => $url]);
    }

    /**
     * No. order atau resi yang di-scan. Paket yang selesai picking langsung dibuka untuk dikemas,
     * jadi packer cukup scan label di keranjang yang dipegangnya. Status lain membuka detail order.
     */
    private function openOrder(Order $order, Request $request): JsonResponse
    {
        if ($order->status === 'CANCELLED') {
            return response()->json(['ok' => false, 'message' => "Order {$order->order_number} dibatalkan. Jangan dikemas; kembalikan barangnya lewat Kembalikan ke Rak."], 422);
        }
        // Packing yang menunggu barang pengganti (lapor masalah) tetap dibuka di layar packing-nya
        if ($order->status === 'PICKING' && ($packing = $order->packingSession)) {
            return $this->open("Membuka packing {$order->order_number}...", route('gudang.packing.show', $packing->id));
        }
        if ($order->status !== 'PACKING') {
            return $this->open("Membuka order {$order->order_number}...", route('gudang.outbound.show', $order->id));
        }
        // Keranjang milik packing gabungan: buka layarnya dengan keranjang ini terpilih
        if (($packing = $order->packingSession) && $packing->packing_batch_id && $packing->status === 'IN_PROGRESS') {
            $packing->batch?->update(['active_session_id' => $packing->id]);

            return $this->open("Membuka packing gabungan, keranjang {$packing->basket_no}...", route('gudang.packing-batch.show', $packing->packing_batch_id));
        }

        $session = DB::transaction(function () use ($order, $request) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $session = $order->packingSession;
            if (!$session) {
                $session = Packing::createForOrder($order, auth()->id());
                $this->audit('CREATE', 'PACKING', ['session_number' => $session->session_number, 'order_id' => $order->id, 'via' => 'scan order'], $request);
            }
            $this->startSession($session, 'PACKING', $request);

            return $session;
        });

        return $this->open("Mulai packing {$order->order_number}...", route('gudang.packing.show', $session->id));
    }

    /**
     * Picking yang sedang dikerjakan user ini dulu; bila tidak ada, gabungkan order CONFIRMED
     * dengan batas kirim paling dekat (atau picking biasa bila hanya ada satu order).
     */
    private function startPicking(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $batch = PickingBatch::where('user_id', $userId)->where('status', 'IN_PROGRESS')->oldest()->first();
        if ($batch) {
            return $this->open("Melanjutkan picking gabungan {$batch->batch_number}...", route('gudang.picking-batch.show', $batch));
        }

        $started = DB::transaction(function () use ($userId, $request) {
            $session = PickingSession::where('user_id', $userId)
                ->whereNull('batch_id')
                ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                ->oldest()
                ->lockForUpdate()
                ->first();

            if (!$session) {
                $orders = PickingBatchController::waitingOrders()->with('items')
                    ->limit(PickingBatch::AUTO_ORDERS)->lockForUpdate()->get();
                if ($orders->isEmpty()) {
                    return null;
                }
                if ($orders->count() > 1) {
                    return PickingBatchController::createBatch($orders, $userId, $request->ip());
                }

                $session = Picking::createForOrder($orders->first(), $userId);
                $this->audit('CREATE', 'PICKING', ['session_number' => $session->session_number, 'order_id' => $session->order_id, 'via' => 'QR perintah'], $request);
            }

            $this->startSession($session, 'PICKING', $request);

            return $session;
        });

        if (!$started) {
            return response()->json(['ok' => false, 'message' => 'Tidak ada order yang menunggu picking.'], 404);
        }
        if ($started instanceof PickingBatch) {
            return $this->open("Picking gabungan {$started->sessions()->count()} order. Siapkan keranjang bernomor...", route('gudang.picking-batch.show', $started));
        }

        return $this->open('Mulai picking ' . ($started->order?->order_number ?? $started->session_number) . '...', route('gudang.picking.show', $started->id));
    }

    /**
     * Packing yang sedang dikerjakan user ini dulu; bila tidak ada, kemas sekaligus keranjang yang menunggu
     * (packing gabungan), atau ambil order selesai picking yang terlama bila hanya ada satu.
     */
    private function startPacking(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $batch = PackingBatch::where('user_id', $userId)->where('status', 'IN_PROGRESS')->oldest()->first();
        if ($batch) {
            return $this->open("Melanjutkan packing gabungan {$batch->batch_number}...", route('gudang.packing-batch.show', $batch));
        }

        $session = DB::transaction(function () use ($userId, $request) {
            $session = PackingSession::where('user_id', $userId)
                ->whereNull('packing_batch_id')
                ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                ->oldest()
                ->lockForUpdate()
                ->first();

            if (!$session) {
                $together = Order::whereIn('id', Packing::autoOrders()->pluck('id'))->lockForUpdate()->get()
                    ->filter(fn ($order) => $order->status === 'PACKING' && !$order->packingSession()->exists());
                if ($together->count() > 1) {
                    return Packing::createBatch($together, $userId, $request->ip());
                }

                $order = Packing::waitingOrders()->lockForUpdate()->first();
                if (!$order) {
                    return null;
                }

                $session = Packing::createForOrder($order, $userId);
                $this->audit('CREATE', 'PACKING', ['session_number' => $session->session_number, 'order_id' => $order->id, 'via' => 'QR perintah'], $request);
            }

            $this->startSession($session, 'PACKING', $request);

            return $session;
        });

        if (!$session) {
            return response()->json(['ok' => false, 'message' => 'Tidak ada order yang menunggu packing.'], 404);
        }
        if ($session instanceof PackingBatch) {
            return $this->open("Packing gabungan {$session->sessions()->count()} keranjang...", route('gudang.packing-batch.show', $session));
        }

        return $this->open('Mulai packing ' . ($session->order?->order_number ?? $session->session_number) . '...', route('gudang.packing.show', $session->id));
    }

    private function startSession(PickingSession|PackingSession $session, string $module, Request $request): void
    {
        if ($session->status !== 'PENDING') {
            return;
        }

        $session->update(['status' => 'IN_PROGRESS', 'started_at' => now()]);
        $this->audit('START', $module, ['session_number' => $session->session_number, 'via' => 'QR perintah'], $request);
    }

    private function audit(string $action, string $module, array $values, Request $request): void
    {
        AuditTrail::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'module' => $module,
            'new_values' => json_encode($values),
            'ip_address' => $request->ip(),
        ]);
    }
}
