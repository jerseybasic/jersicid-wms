<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\DatabaseMessage;

class POStatusUpdated extends Notification
{
    use Queueable;

    protected $po;
    protected $oldStatus;
    protected $newStatus;
    protected $updatedBy;

    public function __construct($po, $oldStatus, $newStatus, $updatedBy)
    {
        $this->po = $po;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->updatedBy = $updatedBy;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        $statusLabels = [
            'DRAFT' => 'Draft',
            'PENDING' => 'Pending Approval',
            'APPROVED' => 'Approved',
            'IN_PRODUCTION' => 'Sedang Diproduksi',
            'READY_FOR_PICKUP' => 'Siap Diambil',
            'RECEIVED' => 'Sudah Diterima',
            'CANCELLED' => 'Dibatalkan',
        ];

        $vendorName = $this->po->vendor?->name ?? 'Vendor Tidak Diketahui';
        $updaterName = $this->updatedBy->name ?? 'Sistem';

        return [
            'po_id' => $this->po->id,
            'po_number' => $this->po->po_number,
            'vendor_name' => $vendorName,
            'old_status' => $statusLabels[$this->oldStatus] ?? $this->oldStatus,
            'new_status' => $statusLabels[$this->newStatus] ?? $this->newStatus,
            'updated_by' => $updaterName,
            'defect_count' => $this->po->defect_count ?? 0,
            'message' => "PO {$this->po->po_number} ({$vendorName}) status berubah menjadi '{$statusLabels[$this->newStatus]}' oleh {$updaterName}.",
            'link' => route('po.show', $this->po->id),
            'type' => $this->newStatus === 'READY_FOR_PICKUP' ? 'success' : 
                     ($this->newStatus === 'CANCELLED' ? 'danger' : 'info'),
            'icon' => $this->newStatus === 'READY_FOR_PICKUP' 
                ? 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'
                : 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'color' => $this->newStatus === 'READY_FOR_PICKUP' ? 'emerald' : 'cyan',
        ];
    }
}