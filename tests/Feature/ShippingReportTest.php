<?php

namespace Tests\Feature;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Laporan ketepatan kirim: order yang dikirim dibandingkan dengan batas kirimnya.
 */
class ShippingReportTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shipped(string $number, string $courier, string $shipBy, string $shippedAt): Order
    {
        return Order::create([
            'order_number' => $number,
            'customer_name' => "Pembeli {$number}",
            'courier' => $courier,
            'status' => 'SHIPPED',
            'ship_by' => Carbon::parse($shipBy),
            'shipped_at' => Carbon::parse($shippedAt),
        ]);
    }

    public function test_report_shows_on_time_rate_per_day_and_courier(): void
    {
        Carbon::setTestNow('2026-10-23 10:00:00');
        $this->shipped('ORD-1', 'JNE', '2026-10-21 23:59:59', '2026-10-21 16:00');
        $this->shipped('ORD-2', 'JNE', '2026-10-21 23:59:59', '2026-10-22 09:30');   // terlambat
        $this->shipped('ORD-3', 'SPX', '2026-10-22 23:59:59', '2026-10-22 15:00');
        $this->shipped('ORD-4', 'SPX', '2026-10-22 23:59:59', '2026-10-22 17:00');
        $this->shipped('ORD-OLD', 'SPX', '2026-08-01 23:59:59', '2026-08-05 10:00'); // di luar periode
        Order::create(['order_number' => 'ORD-OPEN', 'customer_name' => 'X', 'status' => 'CONFIRMED', 'ship_by' => Carbon::parse('2026-10-22 23:59:59')]);

        $response = $this->actingAs($this->userWithRole('SUPERVISOR'))->get(route('reports.shipping'))->assertOk();

        $this->assertSame(4, $response->viewData('summary')['shipped']);
        $this->assertSame(1, $response->viewData('summary')['late']);
        $this->assertEquals(75.0, $response->viewData('summary')['rate']);
        $this->assertSame(1, $response->viewData('summary')['open_late']);
        $this->assertEquals(50.0, $response->viewData('couriers')['JNE']['rate']);
        $this->assertEquals(100.0, $response->viewData('couriers')['SPX']['rate']);
        $this->assertSame(['ORD-2'], $response->viewData('late')->pluck('order_number')->all());
        $response->assertSee('Ketepatan Kirim')->assertSee('ORD-2')->assertSee('10 jam')->assertDontSee('ORD-OLD');

        $this->get(route('reports.shipping', ['export' => 'excel']))->assertOk()->assertDownload();
        $this->get(route('reports.index'))->assertSee('Ketepatan Kirim');

        $this->actingAs($this->userWithRole('STAFF_GUDANG'))->get(route('reports.shipping'))->assertForbidden();
    }
}
