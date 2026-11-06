<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Status order setelah paket keluar dari gudang:
 *  - Dikirim → Selesai otomatis bila sudah lebih dari N hari sejak dikirim (N di Pengaturan, 0 = mati).
 *  - Dikirim/Selesai → Diretur saat paket returnya diterima (dicari lewat no. resi atau no. order).
 *  - Diretur → kembali ke status sebelumnya bila semua returnya dihapus karena salah input.
 *
 * Pengecekan otomatis dipicu cron `schedule:run` (tiap jam) dan, sebagai cadangan bila cron belum
 * dipasang, oleh agen/makro WhatsApp yang sedang tersambung (paling sering sekali per jam).
 * Setiap perubahan status dicatat di audit trail dan tampil di riwayat order.
 */
class OrderAutoComplete
{
    public const DEFAULT_DAYS = 3;
    public const MAX_DAYS = 60;

    private const LAST_RUN_KEY = 'order_auto_complete_last_run';
    private const TICK_KEY = 'order-auto-complete-tick';
    private const TICK_SECONDS = 3600;

    /** Berapa hari setelah dikirim order otomatis Selesai. 0 = fitur dimatikan. */
    public static function days(): int
    {
        $value = SystemSetting::get('order_auto_complete_days');

        return $value === null || $value === '' ? self::DEFAULT_DAYS : min(self::MAX_DAYS, max(0, (int) $value));
    }

    public static function enabled(): bool
    {
        return self::days() > 0;
    }

    /** Order Dikirim yang sudah lebih dari N hari sejak dikirim */
    public static function dueQuery(?Carbon $now = null)
    {
        $cutoff = ($now ?? now())->copy()->subDays(self::days());

        return Order::where('status', 'SHIPPED')
            ->whereNotNull('shipped_at')
            ->where('shipped_at', '<=', $cutoff);
    }

    /**
     * Ubah order Dikirim yang sudah lewat batas hari menjadi Selesai. Mengembalikan jumlah order yang diubah.
     */
    public static function run(string $trigger = 'cron', ?Carbon $now = null): int
    {
        if (!self::enabled()) {
            return 0;
        }

        $now ??= now();
        $days = self::days();
        $done = 0;

        self::dueQuery($now)->orderBy('shipped_at')->select('id')->chunkById(200, function ($rows) use (&$done, $days, $now) {
            foreach ($rows as $row) {
                $done += (int) DB::transaction(function () use ($row, $days, $now) {
                    $order = Order::lockForUpdate()->find($row->id);
                    // Bisa saja sudah diselesaikan manual atau diretur sejak query dijalankan
                    if (!$order || $order->status !== 'SHIPPED' || !$order->shipped_at || $order->shipped_at->gt($now->copy()->subDays($days))) {
                        return false;
                    }
                    $order->update(['status' => 'COMPLETED']);
                    AuditTrail::create([
                        'user_id' => null,
                        'action' => 'UPDATE_STATUS',
                        'module' => 'OUTBOUND',
                        'old_values' => ['status' => 'SHIPPED'],
                        'new_values' => [
                            'status' => 'COMPLETED',
                            'order_number' => $order->order_number,
                            'auto' => "lebih dari {$days} hari sejak dikirim ({$order->shipped_at->format('d/m/Y H:i')})",
                        ],
                        'ip_address' => null,
                    ]);

                    return true;
                });
            }
        });

        SystemSetting::set(self::LAST_RUN_KEY, json_encode(['at' => $now->toDateTimeString(), 'count' => $done, 'trigger' => $trigger]),
            'ORDER', 'Terakhir pengecekan order Dikirim → Selesai otomatis');

        return $done;
    }

    /** Cadangan dari agen/makro WhatsApp: paling sering sekali per jam, tidak pernah menggagalkan request */
    public static function tick(string $trigger): void
    {
        try {
            if (Cache::add(self::TICK_KEY, 1, self::TICK_SECONDS)) {
                self::run($trigger);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array{at: ?Carbon, count: int, trigger: ?string} */
    public static function lastRun(): array
    {
        $last = json_decode((string) SystemSetting::get(self::LAST_RUN_KEY, ''), true) ?: [];

        return [
            'at' => isset($last['at']) ? Carbon::parse($last['at']) : null,
            'count' => (int) ($last['count'] ?? 0),
            'trigger' => $last['trigger'] ?? null,
        ];
    }

    /**
     * Paket retur diterima: order Dikirim/Selesai menjadi Diretur. Order harus sudah dikunci, di dalam transaksi.
     */
    public static function markReturned(Order $order, OrderReturn $return, ?string $userId, ?string $ip): void
    {
        if (!in_array($order->status, ['SHIPPED', 'COMPLETED'], true)) {
            return;
        }

        $old = $order->status;
        $order->update(['status' => 'RETURNED']);
        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'UPDATE_STATUS',
            'module' => 'OUTBOUND',
            'old_values' => ['status' => $old],
            'new_values' => [
                'status' => 'RETURNED',
                'order_number' => $order->order_number,
                'reason' => "Retur {$return->return_number} diterima" . ($order->tracking_number ? " (resi {$order->tracking_number})" : ''),
            ],
            'ip_address' => $ip,
        ]);
    }

    /**
     * Retur terakhir order Diretur dihapus (salah input): kembalikan ke status sebelum diretur.
     * Order harus sudah dikunci, di dalam transaksi.
     */
    public static function undoReturned(Order $order, ?string $userId, ?string $ip): void
    {
        if ($order->status !== 'RETURNED') {
            return;
        }
        $order->load('items');
        if (OrderReprocess::returnedQty($order)->sum() > 0) {
            return; // masih ada retur lain
        }

        $previous = self::statusBeforeReturn($order);
        $order->update(['status' => $previous]);
        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'UPDATE_STATUS',
            'module' => 'OUTBOUND',
            'old_values' => ['status' => 'RETURNED'],
            'new_values' => [
                'status' => $previous,
                'order_number' => $order->order_number,
                'reason' => 'Returnya dihapus, status dikembalikan',
            ],
            'ip_address' => $ip,
        ]);
    }

    /** Status order tepat sebelum menjadi Diretur, dari audit trail; jika tidak ada, dihitung dari tanggal kirim */
    private static function statusBeforeReturn(Order $order): string
    {
        $trail = AuditTrail::where('module', 'OUTBOUND')->where('action', 'UPDATE_STATUS')
            ->where('new_values', 'like', '%RETURNED%')
            ->where('new_values', 'like', '%' . $order->order_number . '%')
            ->latest('id')
            ->get()
            ->first(fn ($t) => ($t->valuesOf('new_values')['order_number'] ?? null) === $order->order_number);
        $old = $trail?->valuesOf('old_values')['status'] ?? null;
        if (in_array($old, ['SHIPPED', 'COMPLETED'], true)) {
            return $old;
        }

        return self::enabled() && $order->shipped_at && $order->shipped_at->lte(now()->subDays(self::days())) ? 'COMPLETED' : 'SHIPPED';
    }
}
