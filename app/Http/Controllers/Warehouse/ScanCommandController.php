<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\Order;
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
                ? $this->open("Membuka order {$order->order_number}...", route('gudang.outbound.show', $order->id))
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
     * Picking yang sedang dikerjakan user ini dulu; bila tidak ada, ambil order CONFIRMED dengan batas kirim paling dekat.
     */
    private function startPicking(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $batch = PickingBatch::where('user_id', $userId)->where('status', 'IN_PROGRESS')->oldest()->first();
        if ($batch) {
            return $this->open("Melanjutkan picking gabungan {$batch->batch_number}...", route('gudang.picking-batch.show', $batch));
        }

        $session = DB::transaction(function () use ($userId, $request) {
            $session = PickingSession::where('user_id', $userId)
                ->whereNull('batch_id')
                ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                ->oldest()
                ->lockForUpdate()
                ->first();

            if (!$session) {
                $order = Order::where('status', 'CONFIRMED')
                    ->whereDoesntHave('pickingSession')
                    ->orderBy('ship_by')
                    ->orderBy('created_at')
                    ->lockForUpdate()
                    ->first();
                if (!$order) {
                    return null;
                }

                $session = Picking::createForOrder($order->load('items'), $userId);
                $this->audit('CREATE', 'PICKING', ['session_number' => $session->session_number, 'order_id' => $order->id, 'via' => 'QR perintah'], $request);
            }

            $this->startSession($session, 'PICKING', $request);

            return $session;
        });

        if (!$session) {
            return response()->json(['ok' => false, 'message' => 'Tidak ada order yang menunggu picking.'], 404);
        }

        return $this->open('Mulai picking ' . ($session->order?->order_number ?? $session->session_number) . '...', route('gudang.picking.show', $session->id));
    }

    /**
     * Packing yang sedang dikerjakan user ini dulu; bila tidak ada, ambil order selesai picking yang terlama.
     */
    private function startPacking(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $session = DB::transaction(function () use ($userId, $request) {
            $session = PackingSession::where('user_id', $userId)
                ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                ->oldest()
                ->lockForUpdate()
                ->first();

            if (!$session) {
                $order = Order::where('status', 'PACKING')
                    ->whereDoesntHave('packingSession')
                    ->orderBy('ship_by')
                    ->orderBy('picked_at')
                    ->orderBy('created_at')
                    ->lockForUpdate()
                    ->first();
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
