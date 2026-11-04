<?php

namespace Tests\Feature;

use App\Models\CourierHandover;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SystemSetting;
use App\Models\WhatsAppMessage;
use App\Services\DailyShipmentReport;
use App\Services\WhatsApp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Serah terima "drop sendiri" saat kurir tidak menjemput, dan laporan kiriman harian ke WhatsApp
 * (nomor pribadi atau grup).
 */
class DailyShipmentReportTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $number, ?string $courier, ?string $resi, string $status = 'READY_TO_SHIP', array $extra = []): Order
    {
        $order = Order::create([
            'order_number' => $number,
            'customer_name' => 'Pembeli ' . $number,
            'courier' => $courier,
            'tracking_number' => $resi,
            'status' => $status,
            'packed_at' => now(),
        ] + $extra);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'JSC-A-L', 'qty_ordered' => 1, 'qty_picked' => 1, 'qty_packed' => 1, 'price' => 1000]);

        return $order;
    }

    public function test_parcels_not_picked_up_can_be_dropped_off_ourselves(): void
    {
        $this->makeSku('JSC-A-L');
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $a = $this->order('JT-1', 'J&T Express', 'JT001');
        $b = $this->order('JT-2', 'J&T Express', 'JT002');

        $this->get(route('gudang.handover'))->assertOk()->assertSee('Tidak dipickup? Drop sendiri');

        // Pilih "Drop sendiri" dari halaman per ekspedisi
        $this->post(route('gudang.handover.store'), ['courier' => 'J&T', 'method' => 'DROP']);
        $handover = CourierHandover::sole();
        $this->assertSame(CourierHandover::DROP, $handover->method);
        $this->get(route('gudang.handover.show', $handover))->assertOk()->assertSee('Tempat drop')->assertSee('Drop sendiri');

        $this->postJson(route('gudang.handover.scan', $handover), ['code' => 'JT001'])->assertOk();
        $this->postJson(route('gudang.handover.scan', $handover), ['code' => 'JT002'])->assertOk();

        // Tempat drop wajib diisi
        $this->post(route('gudang.handover.finish', $handover), ['method' => 'DROP'])->assertSessionHasErrors('drop_point');
        $this->assertSame('READY_TO_SHIP', $a->fresh()->status);

        $this->post(route('gudang.handover.finish', $handover), ['method' => 'DROP', 'drop_point' => 'Agen J&T Cimahi', 'driver_name' => 'Mbak Rina'])
            ->assertRedirect(route('gudang.handover.print', $handover))
            ->assertSessionHas('success', '2 paket J&T di-drop sendiri ke Agen J&T Cimahi dan berstatus Dikirim.');

        $handover->refresh();
        $this->assertSame(['COMPLETED', 'DROP', 'Agen J&T Cimahi'], [$handover->status, $handover->method, $handover->drop_point]);
        $this->assertSame('SHIPPED', $b->fresh()->status);
        $this->assertDatabaseHas('audit_trails', ['action' => 'HANDOVER']);

        $this->get(route('gudang.handover.print', $handover))->assertOk()
            ->assertSee('Drop sendiri ke Agen J&amp;T Cimahi', false)->assertSee('Diterima oleh (agen J&amp;T)', false);
        $this->get(route('gudang.handover'))->assertSee('Drop sendiri · Agen J&amp;T Cimahi', false);
    }

    public function test_pickup_stays_the_default_and_drop_point_is_ignored(): void
    {
        $this->makeSku('JSC-A-L');
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $this->order('SP-1', 'SPX Standard', 'SPX001');

        $this->post(route('gudang.handover.store'), ['courier' => 'SPX']);
        $handover = CourierHandover::sole();
        $this->postJson(route('gudang.handover.scan', $handover), ['code' => 'SPX001'])->assertOk();
        $this->post(route('gudang.handover.finish', $handover), ['drop_point' => 'tidak dipakai'])->assertSessionHas('success');

        $handover->refresh();
        $this->assertSame([CourierHandover::PICKUP, null], [$handover->method, $handover->drop_point]);
        $this->get(route('gudang.handover.print', $handover))->assertSee('Dipickup kurir');
    }

    public function test_report_counts_pickup_drop_ready_and_pending(): void
    {
        Carbon::setTestNow('2026-11-02 17:00:00');
        $this->makeSku('JSC-A-L');
        $user = $this->userWithRole('STAFF_GUDANG');
        SystemSetting::set('company_name', 'JERSIC.ID');

        $pickup = CourierHandover::create(['courier' => 'SPX', 'user_id' => $user->id, 'status' => 'COMPLETED', 'completed_at' => now()]);
        $drop = CourierHandover::create(['courier' => 'J&T', 'method' => 'DROP', 'drop_point' => 'Agen J&T Cimahi', 'user_id' => $user->id, 'status' => 'COMPLETED', 'completed_at' => now()]);
        $yesterday = CourierHandover::create(['courier' => 'JNE', 'user_id' => $user->id, 'status' => 'COMPLETED', 'completed_at' => now()->subDay()]);
        foreach (['SP-1' => $pickup, 'SP-2' => $pickup, 'JT-1' => $drop, 'JNE-1' => $yesterday] as $number => $handover) {
            $order = $this->order($number, $handover->courier, $number, 'SHIPPED', ['shipped_at' => $handover->completed_at]);
            $handover->items()->create(['order_id' => $order->id]);
        }

        $this->order('SP-9', 'SPX Standard', 'SPX009');
        $this->order('SP-8', 'Shopee Xpress', 'SPX008');
        $this->order('JNE-9', 'JNE REG', 'JNE009');
        $this->order('PK-1', 'SPX', null, 'PACKING');
        $this->order('PI-1', 'SPX', null, 'PICKING');
        $this->order('PE-1', 'SPX', null, 'PENDING', ['ship_by' => now()->subDay()]);
        $this->order('WS-1', 'SPX', null, 'PENDING', ['waiting_stock_at' => now()]);

        $body = DailyShipmentReport::message();

        $this->assertStringContainsString('*Laporan kiriman harian JERSIC.ID*', $body);
        $this->assertStringContainsString('Senin, 02/11/2026', $body);
        $this->assertStringContainsString("*Dikirim: 3 paket*\n• J&T: 1 (drop sendiri 1 di Agen J&T Cimahi)\n• SPX: 2 (dipickup 2)", $body);
        $this->assertStringContainsString('Dipickup kurir: 2 · Drop sendiri: 1', $body);
        $this->assertStringContainsString("*Siap kirim, belum diserahkan: 3*\n• JNE: 1\n• SPX: 2", $body);
        $this->assertStringContainsString('*Masih diproses: 2* (picking 1 · packing 1)', $body);
        $this->assertStringContainsString('*Tertunda: 2* (belum dikonfirmasi 1 · menunggu stok 1)', $body);
        $this->assertStringContainsString('⚠ Lewat batas kirim: 1 order', $body);
        $this->assertStringNotContainsString('JNE: 1 (', $body); // serah terima kemarin tidak dihitung
    }

    public function test_targets_accept_personal_numbers_and_group_names(): void
    {
        $targets = DailyShipmentReport::targets("0812-3456-7890\n  Gudang  Jersic \n+62 812 3456 7890\n\nOwner & Admin");

        $this->assertSame([
            ['to' => '6281234567890', 'type' => 'nomor'],
            ['to' => 'Gudang Jersic', 'type' => 'grup'],
            ['to' => 'Owner & Admin', 'type' => 'grup'],
        ], $targets);
    }

    public function test_scheduler_sends_once_per_day_after_the_set_time(): void
    {
        Carbon::setTestNow('2026-11-02 16:59:00');

        // Belum aktif: tidak ada yang diantrekan
        $this->artisan('wms:kirim-laporan-kiriman')->assertSuccessful();
        $this->assertSame(0, WhatsAppMessage::count());

        WhatsApp::generateToken();
        DailyShipmentReport::save(true, '17:00', "081234567890\nGudang Jersic");

        $this->assertFalse(DailyShipmentReport::due());
        Carbon::setTestNow('2026-11-02 17:00:00');
        $this->assertTrue(DailyShipmentReport::due());

        $this->artisan('wms:kirim-laporan-kiriman')->expectsOutput('Laporan kiriman harian masuk antrean WhatsApp untuk 2 penerima.')->assertSuccessful();
        $this->assertSame(['6281234567890', 'Gudang Jersic'], WhatsAppMessage::orderBy('id')->pluck('recipient')->all());
        $this->assertSame([DailyShipmentReport::TOPIC], WhatsAppMessage::distinct()->pluck('topic')->all());

        // Menit berikutnya tidak dikirim lagi; besok dikirim lagi
        Carbon::setTestNow('2026-11-02 17:01:00');
        $this->assertFalse(DailyShipmentReport::due());
        $this->artisan('wms:kirim-laporan-kiriman')->assertSuccessful();
        $this->assertSame(2, WhatsAppMessage::count());

        Carbon::setTestNow('2026-11-03 17:05:00');
        $this->assertTrue(DailyShipmentReport::due());

        // Makro menerima nama grup apa adanya
        $token = WhatsApp::generateToken();
        $this->getJson(route('api.whatsapp.queue'), ['Authorization' => 'Bearer ' . $token])
            ->assertOk()->assertJsonPath('messages.1.to', 'Gudang Jersic');
    }

    public function test_owner_configures_report_and_sends_a_test(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        $owner = $this->userWithRole('OWNER');
        $this->actingAs($owner);

        $this->get(route('settings.index'))->assertOk()->assertSee('Laporan Kiriman Harian')->assertSee('Belum pernah dikirim.');

        // Aktif tanpa penerima ditolak
        $this->put(route('settings.daily-report'), ['daily_report_enabled' => '1', 'daily_report_time' => '17:30'])
            ->assertSessionHasErrors('daily_report_targets');
        $this->put(route('settings.daily-report'), ['daily_report_time' => '25:00'])->assertSessionHasErrors('daily_report_time');

        $this->put(route('settings.daily-report'), [
            'daily_report_enabled' => '1',
            'daily_report_time' => '17:30',
            'daily_report_targets' => "081234567890\r\nGudang Jersic",
        ])->assertSessionHas('success');
        $this->assertSame(['17:30', true], [DailyShipmentReport::time(), DailyShipmentReport::enabled()]);
        $this->assertDatabaseHas('audit_trails', ['action' => 'UPDATE_DAILY_REPORT']);

        // Kirim uji butuh token makro
        $this->post(route('settings.daily-report.test'))->assertSessionHasErrors('daily_report');
        WhatsApp::generateToken();
        $this->post(route('settings.daily-report.test'))->assertSessionHas('success');
        $this->assertSame(2, WhatsAppMessage::where('topic', DailyShipmentReport::TOPIC)->count());

        // Kirim uji tidak menggeser jadwal harian
        Carbon::setTestNow('2026-11-02 17:30:00');
        $this->assertTrue(DailyShipmentReport::due());

        $this->get(route('settings.index'))->assertSee('Aktif · tiap hari 17:30')->assertSee('Grup: Gudang Jersic')
            ->assertSee('Log pengiriman laporan')->assertSee('6281234567890');

        $this->actingAs($this->userWithRole('SUPERVISOR'))->post(route('settings.daily-report.test'))->assertForbidden();
    }
}
