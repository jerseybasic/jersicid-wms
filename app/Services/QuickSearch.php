<?php

namespace App\Services;

use App\Models\MasterSku;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\PurchaseOrder;
use App\Models\StockActive;
use App\Models\User;
use App\Models\WarehouseRack;

/**
 * Pencarian cepat di kotak cari atas: order (nomor, resi, nama pembeli), SKU, lokasi rak/bin,
 * PO, dan retur. Hasil dikelompokkan; "exact" menandai kode yang persis sama dengan yang diketik/di-scan.
 */
class QuickSearch
{
    public const MIN_LENGTH = 2;

    private const PO_ROLES = ['OWNER', 'SUPERVISOR', 'ADMIN_GUDANG'];

    /**
     * @return array<int, array{label:string, items:array<int, array{title:string, subtitle:string, url:string, exact:bool}>}>
     */
    public static function run(string $query, User $user, int $limit = 5): array
    {
        $query = trim($query);
        if (mb_strlen($query) < self::MIN_LENGTH) {
            return [];
        }
        $code = strtoupper($query);
        $like = '%' . $query . '%';

        $groups = [];

        $orders = Order::where(fn ($q) => $q->where('order_number', 'like', $like)
            ->orWhere('tracking_number', 'like', $like)
            ->orWhere('customer_name', 'like', $like))
            ->orderByRaw('CASE WHEN UPPER(order_number) = ? OR UPPER(tracking_number) = ? THEN 0 ELSE 1 END', [$code, $code])
            ->latest()
            ->limit($limit)
            ->get(['id', 'order_number', 'tracking_number', 'customer_name', 'courier', 'status']);
        $groups[] = ['label' => 'Order', 'items' => $orders->map(fn ($o) => [
            'title' => $o->order_number,
            'subtitle' => collect([$o->customer_name, $o->status_label, $o->tracking_number ? "Resi {$o->tracking_number}" : null])->filter()->implode(' · '),
            'url' => route('gudang.outbound.show', $o->id),
            'exact' => strtoupper($o->order_number) === $code || strtoupper((string) $o->tracking_number) === $code,
        ])->all()];

        $skus = MasterSku::where(fn ($q) => $q->where('sku', 'like', $like)->orWhere('product_name', 'like', $like))
            ->orderByRaw('CASE WHEN UPPER(sku) = ? THEN 0 ELSE 1 END', [$code])
            ->orderBy('sku')
            ->limit($limit)
            ->get(['sku', 'product_name', 'size']);
        $stock = StockActive::whereIn('sku', $skus->pluck('sku'))->groupBy('sku')->selectRaw('sku, SUM(qty) as total')->pluck('total', 'sku');
        $groups[] = ['label' => 'SKU', 'items' => $skus->map(fn ($s) => [
            'title' => $s->sku,
            'subtitle' => trim("{$s->product_name} {$s->size}") . ' · stok ' . (int) ($stock[$s->sku] ?? 0) . ' pcs',
            'url' => route('gudang.scanner', ['kode' => $s->sku]),
            'exact' => strtoupper($s->sku) === $code,
        ])->all()];

        // Lokasi hanya untuk kode persis (A-01-01 atau rak A-01), supaya tidak membanjiri hasil
        if (WarehouseRack::where('code', $code)->exists() || WarehouseRack::isKnownBin($code)) {
            $isRack = WarehouseRack::where('code', $code)->exists();
            $groups[] = ['label' => 'Lokasi', 'items' => [[
                'title' => $code,
                'subtitle' => $isRack ? 'Rak: lihat isi semua bin' : 'Bin: lihat isinya',
                'url' => route('gudang.scanner', ['kode' => $code]),
                'exact' => true,
            ]]];
        }

        if (in_array($user->role, self::PO_ROLES, true)) {
            $pos = PurchaseOrder::where(fn ($q) => $q->where('po_number', 'like', $like)->orWhere('supplier_name', 'like', $like))
                ->latest()->limit($limit)->get(['id', 'po_number', 'supplier_name', 'status']);
            $groups[] = ['label' => 'Purchase Order', 'items' => $pos->map(fn ($po) => [
                'title' => $po->po_number,
                'subtitle' => collect([$po->supplier_name, $po->status_label])->filter()->implode(' · '),
                'url' => route('po.show', $po->id),
                'exact' => strtoupper($po->po_number) === $code,
            ])->all()];
        }

        $returns = OrderReturn::with('order:id,order_number')
            ->where('return_number', 'like', $like)
            ->latest()->limit($limit)->get(['id', 'return_number', 'order_id', 'reason']);
        $groups[] = ['label' => 'Retur', 'items' => $returns->map(fn ($r) => [
            'title' => $r->return_number,
            'subtitle' => collect([$r->order?->order_number, $r->reason_label])->filter()->implode(' · '),
            'url' => route('gudang.retur.show', $r->id),
            'exact' => strtoupper($r->return_number) === $code,
        ])->all()];

        return array_values(array_filter($groups, fn ($group) => $group['items']));
    }

    /** URL hasil yang kodenya persis sama, jika hanya ada satu */
    public static function exactUrl(array $groups): ?string
    {
        $exact = collect($groups)->pluck('items')->flatten(1)->where('exact', true)->unique('url');

        return $exact->count() === 1 ? $exact->first()['url'] : null;
    }
}
