<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Laporan picker "barang tidak ada di rak". Stok rak belum diubah sampai Owner/Supervisor
 * menyetujui; selama menunggu, stok di rak itu ditahan: tidak dijatah ke picking dan tidak
 * dihitung sebagai stok tersedia.
 */
class MissingStockReport extends Model
{
    protected $fillable = [
        'sku', 'bin_code', 'qty', 'reference_no', 'order_id', 'reported_by',
        'status', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected $casts = [
        'qty' => 'integer',
        'decided_at' => 'datetime',
    ];

    public const STATUS_LABELS = [
        'PENDING' => 'Menunggu persetujuan',
        'APPROVED' => 'Disetujui, stok dinolkan',
        'REJECTED' => 'Ditolak, barang ada',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function masterSku()
    {
        return $this->belongsTo(MasterSku::class, 'sku', 'sku');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Rak yang stok SKU ini sedang ditahan karena dilaporkan kosong */
    public static function heldBins(string $sku): array
    {
        return static::pending()->where('sku', $sku)->pluck('bin_code')->all();
    }

    /**
     * Stok yang ditahan per SKU: jumlah yang dilaporkan, tapi tidak lebih dari stok rak itu sekarang.
     *
     * @return \Illuminate\Support\Collection<string, int>
     */
    public static function heldQtyBySku(iterable $skus)
    {
        return DB::table('missing_stock_reports as r')
            ->join('stock_actives as s', fn ($j) => $j->on('s.sku', '=', 'r.sku')->on('s.bin_code', '=', 'r.bin_code'))
            ->where('r.status', 'PENDING')
            ->whereIn('r.sku', collect($skus)->all())
            ->groupBy('r.sku')
            ->selectRaw('r.sku, SUM(CASE WHEN s.qty < r.qty THEN s.qty ELSE r.qty END) as qty')
            ->pluck('qty', 'r.sku')
            ->map(fn ($qty) => max(0, (int) $qty));
    }
}
