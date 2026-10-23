<?php

namespace Tests\Feature;

use App\Console\Commands\SendShipReminder;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Pengingat order terlambat: pesan WhatsApp sebelum jam potong dan angka di lonceng notifikasi.
 */
class ShipReminderTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $number, string $status, string $shipBy, ?string $courier = 'JNE'): Order
    {
        return Order::create(['order_number' => $number, 'customer_name' => 'Pembeli', 'status' => $status, 'courier' => $courier, 'ship_by' => Carbon::parse($shipBy)]);
    }

    public function test_reminder_lists_late_and_due_today_orders_per_stage(): void
    {
        Carbon::setTestNow('2026-10-23 13:00:00');

        // Belum diatur: tidak ada yang diantrekan
        $this->artisan('wms:kirim-pengingat-kirim')->assertSuccessful();
        $this->assertSame(0, WhatsAppMessage::count());

        WhatsApp::generateToken();
        User::factory()->role('STAFF_GUDANG')->create(['whatsapp' => '081200001111', 'notify_whatsapp' => true, 'status' => 'AKTIF']);

        $this->order('ORD-BESOK', 'CONFIRMED', '2026-10-24 23:59:59');
        $this->order('ORD-SENT', 'SHIPPED', '2026-10-20 23:59:59');
        $this->artisan('wms:kirim-pengingat-kirim')->expectsOutput('Tidak ada order yang terlambat atau harus dikirim hari ini.')->assertSuccessful();
        $this->assertSame(0, WhatsAppMessage::count());

        $this->order('ORD-LATE', 'CONFIRMED', '2026-10-22 23:59:59', 'SPX');
        $this->order('ORD-TODAY', 'READY_TO_SHIP', '2026-10-23 23:59:59', null);

        $this->artisan('wms:kirim-pengingat-kirim')->assertSuccessful();
        $body = WhatsAppMessage::sole()->body;
        $this->assertStringContainsString('1 order terlambat · 1 order harus dikirim hari ini', $body);
        $this->assertStringContainsString("*Belum diambil: 1*\n• ORD-LATE (SPX) — ⚠ terlambat", $body);
        $this->assertStringContainsString("*Siap kirim, tunggu kurir: 1*\n• ORD-TODAY — hari ini", $body);
        $this->assertStringNotContainsString('ORD-BESOK', $body);
        $this->assertStringNotContainsString('ORD-SENT', $body);
    }

    public function test_reminder_runs_two_hours_before_cutoff(): void
    {
        $this->assertSame(13, SendShipReminder::hour());
        SystemSetting::set('ship_cutoff_hour', '1', 'ORDER');
        $this->assertSame(0, SendShipReminder::hour());

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'wms:kirim-pengingat-kirim'));
        $this->assertNotNull($event);

        SystemSetting::set('ship_cutoff_hour', '15', 'ORDER');
        Carbon::setTestNow('2026-10-23 13:00:00');
        $this->assertTrue($event->filtersPass($this->app));
        Carbon::setTestNow('2026-10-23 14:00:00');
        $this->assertFalse($event->filtersPass($this->app));
    }

    public function test_bell_shows_orders_past_deadline(): void
    {
        Carbon::setTestNow('2026-10-23 10:00:00');
        $this->order('ORD-LATE', 'PACKING', '2026-10-22 23:59:59');
        $this->order('ORD-OK', 'CONFIRMED', '2026-10-23 23:59:59');

        $items = $this->actingAs($this->userWithRole('STAFF_GUDANG'))->get(route('dashboard.gudang'))->assertOk()
            ->assertSee('Order lewat batas kirim')
            ->viewData('workItems');
        $this->assertSame(1, collect($items)->firstWhere('key', 'ship_late')['count']);
    }
}
