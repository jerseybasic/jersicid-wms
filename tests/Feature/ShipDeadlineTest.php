<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SystemSetting;
use App\Services\ShipDeadline;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Batas kirim order: dihitung dari jam potong, diambil dari file marketplace bila ada,
 * lalu ditandai Terlambat / Kirim hari ini di Tugas dan Outbound.
 */
class ShipDeadlineTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $number, string $status, ?Carbon $shipBy = null): Order
    {
        return Order::create(array_filter([
            'order_number' => $number,
            'customer_name' => "Pembeli {$number}",
            'status' => $status,
            'ship_by' => $shipBy,
        ]));
    }

    public function test_deadline_follows_cutoff_hour_and_grace_days(): void
    {
        Carbon::setTestNow('2026-10-23 10:00:00');

        $this->assertSame('2026-10-23 23:59:59', ShipDeadline::forOrderedAt(now())->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-24', ShipDeadline::forOrderedAt(Carbon::parse('2026-10-23 15:00'))->toDateString());

        SystemSetting::set('ship_cutoff_hour', '12', 'ORDER');
        SystemSetting::set('ship_grace_days', '1', 'ORDER');
        $this->assertSame('2026-10-24', ShipDeadline::forOrderedAt(Carbon::parse('2026-10-23 11:59'))->toDateString());
        $this->assertSame('2026-10-25', ShipDeadline::forOrderedAt(Carbon::parse('2026-10-23 12:00'))->toDateString());

        // Order baru otomatis mendapat batas kirim
        $order = $this->order('ORD-NEW', 'PENDING');
        $this->assertSame('2026-10-24', $order->ship_by->toDateString());
        $this->assertSame(ShipDeadline::TOMORROW, $order->shipStatus());

        $this->assertSame(ShipDeadline::LATE, $this->order('ORD-OLD', 'CONFIRMED', Carbon::parse('2026-10-22 23:59:59'))->shipStatus());
        $this->assertSame(ShipDeadline::TODAY, $this->order('ORD-TODAY', 'CONFIRMED', Carbon::parse('2026-10-23 23:59:59'))->shipStatus());
    }

    public function test_owner_sets_cutoff_hour_in_settings(): void
    {
        $owner = $this->userWithRole('OWNER');

        $this->actingAs($owner)->get(route('settings.index'))->assertOk()->assertSee('Batas kirim order');

        $this->put(route('settings.update'), [
            'company_name' => 'JERSIC.ID',
            'low_stock_threshold' => 0,
            'ship_cutoff_hour' => 13,
            'ship_grace_days' => 2,
        ])->assertSessionHas('success');

        $this->assertSame(13, ShipDeadline::cutoffHour());
        $this->assertSame(2, ShipDeadline::graceDays());

        $this->put(route('settings.update'), ['company_name' => 'JERSIC.ID', 'low_stock_threshold' => 0, 'ship_cutoff_hour' => 24])
            ->assertSessionHasErrors('ship_cutoff_hour');
    }

    public function test_tasks_and_outbound_put_urgent_orders_first(): void
    {
        Carbon::setTestNow('2026-10-23 10:00:00');
        $staff = $this->userWithRole('STAFF_GUDANG');

        $this->order('ORD-LATER', 'CONFIRMED', Carbon::parse('2026-10-26 23:59:59'));
        $this->order('ORD-LATE', 'CONFIRMED', Carbon::parse('2026-10-21 23:59:59'));
        $this->order('ORD-TODAY', 'READY_TO_SHIP', Carbon::parse('2026-10-23 23:59:59'));
        $this->order('ORD-SENT', 'SHIPPED', Carbon::parse('2026-10-20 23:59:59'));

        $this->actingAs($staff)->get(route('gudang.tugas'))
            ->assertOk()
            ->assertSee('order terlambat dikirim')
            ->assertSee('order harus dikirim hari ini')
            ->assertSee('Terlambat 2 hari')
            ->assertSee('Kirim hari ini')
            ->assertSeeInOrder(['ORD-LATE', 'ORD-LATER']);

        $this->get(route('gudang.outbound', ['batas' => 'terlambat']))
            ->assertOk()
            ->assertSee('ORD-LATE')
            ->assertDontSee('ORD-LATER')
            ->assertDontSee('ORD-SENT');

        $this->get(route('gudang.outbound', ['batas' => 'hari-ini']))
            ->assertSee('ORD-TODAY')
            ->assertDontSee('ORD-LATE');
    }

    public function test_import_takes_deadline_from_marketplace_file(): void
    {
        Carbon::setTestNow('2026-10-23 10:00:00');
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JER-GOTHIC-M');
        $this->putStock('JER-GOTHIC-M', 'A-01-01', 10);

        $header = ['Nomor Pesanan', 'SKU', 'Jumlah', 'Waktu Pesanan Dibuat', 'Batas Waktu Pengiriman'];
        $sheet = new Spreadsheet();
        $sheet->getActiveSheet()->fromArray([
            $header,
            ['ORD-A', 'JER-GOTHIC-M', '1', '22 Okt 2026 16:10', '25 Okt 2026 23:59'],   // batas dari marketplace
            ['ORD-B', 'JER-GOTHIC-M', '1', '22 Okt 2026 09:00', '--'],                  // dihitung dari jam potong
        ], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'bs') . '.xlsx';
        (new Xlsx($sheet))->save($path);

        $response = $this->actingAs($staff)->post(route('gudang.outbound.import.preview'), [
            'file' => new UploadedFile($path, 'order.xlsx', null, null, true),
        ])->assertOk();
        $this->post(route('gudang.outbound.import.store'), ['token' => $response->viewData('token')])
            ->assertRedirect(route('gudang.outbound'));

        $this->assertSame('2026-10-25 23:59:00', Order::where('order_number', 'ORD-A')->value('ship_by')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-22', Order::where('order_number', 'ORD-B')->first()->ship_by->toDateString());
        $this->assertSame(ShipDeadline::LATE, Order::where('order_number', 'ORD-B')->first()->shipStatus());
    }
}
