<?php

namespace Tests\Unit;

use App\Models\PurchaseOrder;
use PHPUnit\Framework\TestCase;

class PurchaseOrderStatusTest extends TestCase
{
    public function test_po_follows_status_flow_in_order(): void
    {
        $po = new PurchaseOrder(['status' => 'DRAFT']);

        $this->assertTrue($po->canTransitionTo('PENDING'));
        $this->assertFalse($po->canTransitionTo('APPROVED'));

        $po->status = 'PENDING';
        $this->assertTrue($po->canTransitionTo('APPROVED'));

        $po->status = 'IN_PRODUCTION';
        $this->assertTrue($po->canTransitionTo('READY_FOR_PICKUP'));
        $this->assertFalse($po->canTransitionTo('RECEIVED'));
    }

    public function test_finished_po_cannot_change_status(): void
    {
        foreach (['RECEIVED', 'CANCELLED'] as $status) {
            $po = new PurchaseOrder(['status' => $status]);
            $this->assertFalse($po->canTransitionTo('CANCELLED'));
            $this->assertFalse($po->canTransitionTo('APPROVED'));
        }
    }
}
