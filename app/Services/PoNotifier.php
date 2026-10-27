<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\SystemSetting;

/**
 * Notifikasi WhatsApp saat status PO berubah (PRD: po_auto_notify). Masuk ke antrean WhatsApp yang sama
 * dengan stok menipis; tidak mengirim apa-apa jika dimatikan di Pengaturan atau WhatsApp belum diatur.
 */
class PoNotifier
{
    public const SETTING = 'po_auto_notify';

    /** Status yang dikabarkan, beserta kalimat pembukanya */
    private const EVENTS = [
        'PENDING' => 'menunggu persetujuan',
        'APPROVED' => 'disetujui',
        'DRAFT' => 'ditolak dan kembali ke Draft',
        'IN_PRODUCTION' => 'mulai diproduksi',
        'READY_FOR_PICKUP' => 'siap diambil di supplier',
        'RECEIVED' => 'sudah diterima lengkap di gudang',
        'CANCELLED' => 'dibatalkan',
    ];

    public static function enabled(): bool
    {
        return SystemSetting::get(self::SETTING, '1') === '1';
    }

    public static function setEnabled(bool $enabled): void
    {
        SystemSetting::set(self::SETTING, $enabled ? '1' : '0', 'NOTIFICATION', 'Kirim notifikasi WhatsApp saat status PO berubah');
    }

    /** Taruh pesan di antrean WhatsApp. Kegagalan tidak boleh menggagalkan perubahan status PO. */
    public static function statusChanged(PurchaseOrder $po, ?string $byName = null, ?string $reason = null): void
    {
        if (!isset(self::EVENTS[$po->status]) || !self::enabled() || !WhatsApp::configured()) {
            return;
        }

        try {
            WhatsApp::send(self::message($po, $byName, $reason));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function message(PurchaseOrder $po, ?string $byName = null, ?string $reason = null): string
    {
        $po->loadMissing('items');
        $lines = ["*PO {$po->po_number} " . self::EVENTS[$po->status] . '*'];
        $lines[] = 'Supplier: ' . $po->supplier_name;
        $lines[] = $po->items->count() . ' SKU · ' . (int) $po->items->sum('qty_ordered') . ' pcs'
            . ($po->expected_date ? ' · target ' . $po->expected_date->format('d/m/Y') : '');
        if ($byName) {
            $lines[] = 'Oleh: ' . $byName;
        }
        if ($reason) {
            $lines[] = 'Alasan: ' . $reason;
        }
        $lines[] = route('po.show', $po->id);

        return implode("\n", $lines);
    }
}
