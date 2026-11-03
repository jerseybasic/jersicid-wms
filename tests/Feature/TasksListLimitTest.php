<?php

namespace Tests\Feature;

use App\Http\Controllers\Warehouse\TaskController;
use App\Models\Order;
use App\Services\ShipDeadline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Tugas Saya tetap ringan walaupun antreannya panjang: hanya order paling mendesak yang dirender,
 * angka di judul tetap jumlah sebenarnya.
 */
class TasksListLimitTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    public function test_long_queues_show_most_urgent_orders_and_real_totals(): void
    {
        $total = TaskController::LIST_LIMIT + 5;
        for ($i = 1; $i <= $total; $i++) {
            Order::create([
                'order_number' => sprintf('ORD-%03d', $i),
                'customer_name' => 'Budi',
                'shipping_address' => 'Bandung',
                'status' => 'CONFIRMED',
                // ORD-001 paling mendesak (terlambat), sisanya makin lama
                'ship_by' => $i === 1 ? now()->subDay() : now()->addDays($i),
            ]);
        }
        Order::create(['order_number' => 'ORD-TODAY', 'customer_name' => 'Ani', 'shipping_address' => 'Bandung', 'status' => 'READY_TO_SHIP', 'ship_by' => now()->endOfDay()]);

        $response = $this->actingAs($this->userWithRole('STAFF_GUDANG'))->get(route('gudang.tugas'))->assertOk();

        $this->assertCount(TaskController::LIST_LIMIT, $response->viewData('toPick'));
        $this->assertSame('ORD-001', $response->viewData('toPick')->first()->order_number);
        $this->assertSame($total, $response->viewData('totals')['pick']);
        $this->assertSame(1, $response->viewData('deadlines')[ShipDeadline::LATE]);
        $this->assertSame(1, $response->viewData('deadlines')[ShipDeadline::TODAY]);
        $response->assertSee('Menampilkan ' . TaskController::LIST_LIMIT . " paling mendesak dari {$total}")
            ->assertDontSee(sprintf('ORD-%03d', $total));
    }
}
