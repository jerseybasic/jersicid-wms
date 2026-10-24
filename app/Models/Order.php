<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Services\ShipDeadline;
use Illuminate\Support\Str;

class Order extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    /**
     * Alur status order:
     * PENDING → CONFIRMED → PICKING → PACKING → READY_TO_SHIP → SHIPPED → COMPLETED
     * PENDING/CONFIRMED → CANCELLED
     * PICKING/PACKING/READY_TO_SHIP → CANCELLED (barang yang sudah diambil dikembalikan ke rak)
     *
     * Order PENDING dengan waiting_stock_at terisi tampil sebagai "Menunggu Stok".
     */
    public const STATUS_LABELS = [
        'PENDING' => 'Menunggu Konfirmasi',
        'CONFIRMED' => 'Siap Picking',
        'PICKING' => 'Sedang Picking',
        'PACKING' => 'Siap/Sedang Packing',
        'READY_TO_SHIP' => 'Siap Kirim',
        'SHIPPED' => 'Dikirim',
        'COMPLETED' => 'Selesai',
        'CANCELLED' => 'Dibatalkan',
    ];

    public const WAITING_STOCK_LABEL = 'Menunggu Stok';

    /** Status yang bisa dibatalkan walau barangnya sudah mulai diambil dari rak */
    public const IN_PROCESS_STATUSES = ['PICKING', 'PACKING', 'READY_TO_SHIP'];

    protected $fillable = [
        'id',
        'order_number',
        'customer_name',
        'customer_phone',
        'shipping_address',
        'courier',
        'tracking_number',
        'status',
        'created_by',
        'picked_by',
        'packed_by',
        'picked_at',
        'packed_at',
        'shipped_at',
        'ship_by',
        'waiting_stock_at',
        'notes',
    ];

    protected $casts = [
        'picked_at' => 'datetime',
        'packed_at' => 'datetime',
        'shipped_at' => 'datetime',
        'ship_by' => 'datetime',
        'waiting_stock_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
            $model->ship_by ??= ShipDeadline::forOrderedAt(now());
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /** Baris serah terima kurir yang memuat order ini (jika sudah di-scan) */
    public function handoverItem(): HasOne
    {
        return $this->hasOne(CourierHandoverItem::class, 'order_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class, 'order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pickedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picked_by');
    }

    public function packedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packed_by');
    }

    public function pickingSession()
    {
        return $this->hasOne(PickingSession::class, 'order_id');
    }

    public function packingSession()
    {
        return $this->hasOne(PackingSession::class, 'order_id');
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->isWaitingStock()) {
            return self::WAITING_STOCK_LABEL;
        }

        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Order Pending yang ditunda karena stoknya kurang */
    public function isWaitingStock(): bool
    {
        return $this->status === 'PENDING' && $this->waiting_stock_at !== null;
    }

    public function scopeWaitingStock($query)
    {
        return $query->where('status', 'PENDING')->whereNotNull('waiting_stock_at');
    }

    /** Data order masih boleh diubah (belum selesai atau dibatalkan) */
    public function isEditable(): bool
    {
        return !in_array($this->status, ['COMPLETED', 'CANCELLED'], true);
    }

    /** Item order masih boleh diubah: belum ada sesi picking */
    public function itemsEditable(): bool
    {
        return in_array($this->status, ['PENDING', 'CONFIRMED'], true) && !$this->pickingSession;
    }

    /** Order dengan nomor order atau nomor resi ini (huruf besar/kecil diabaikan) */
    public static function findByCode(string $code): ?self
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        return static::whereRaw('UPPER(order_number) = ?', [$code])->first()
            ?? static::whereRaw('UPPER(tracking_number) = ?', [$code])->latest()->first();
    }

    /** Batas kirim order ini (lihat ShipDeadline) */
    public function shipDeadline(): \Carbon\Carbon
    {
        return ShipDeadline::of($this);
    }

    /** late / today / tomorrow / later */
    public function shipStatus(): string
    {
        return ShipDeadline::status($this);
    }
}
