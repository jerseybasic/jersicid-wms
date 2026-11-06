<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\SystemSetting;
use App\Services\OrderAutoComplete;
use App\Services\WhatsApp;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Order Dikirim otomatis Selesai setelah lebih dari N hari (Pengaturan, bawaan 3),
 * dan order Dikirim/Selesai menjadi Diretur saat paket returnya diterima lewat no. resi.
 */
class OrderAutoCompleteTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sentOrder(string $number, string $shippedAt, string $status = 'SHIPPED', string $resi = 'JNE-RT-1'): Order
    {
        $order = Order::create([
            'order_number' => $number,
            'customer_name' => 'Budi',
            'courier' => 'JNE',
            'tracking_number' => $resi,
            'status' => $status,
            'shipped_at' => Carbon::parse($shippedAt),
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_ordered' => 2, 'price' => 150000]);

        return $order->load('items');
    }

    public function test_shipped_orders_older_than_three_days_become_completed_with_audit_trail(): void
    {
        $this->makeSku('JSC-A-L');
        Carbon::setTestNow('2026-11-05 10:00:00');
        $old = $this->sentOrder('ORD-AC-OLD', '2026-11-02 09:00:00', resi: 'R1');      // 3 hari 1 jam
        $fresh = $this->sentOrder('ORD-AC-NEW', '2026-11-02 11:00:00', resi: 'R2');    // belum 3 hari
        $ready = $this->sentOrder('ORD-AC-RDY', '2026-10-01 09:00:00', 'READY_TO_SHIP', 'R3');

        $this->artisan('wms:selesaikan-order')->expectsOutputToContain('1 order Dikirim lebih dari 3 hari')->assertSuccessful();

        $this->assertSame('COMPLETED', $old->fresh()->status);
        $this->assertSame('SHIPPED', $fresh->fresh()->status);
        $this->assertSame('READY_TO_SHIP', $ready->fresh()->status);

        $trail = AuditTrail::where('module', 'OUTBOUND')->where('action', 'UPDATE_STATUS')->latest('id')->firstOrFail();
        $this->assertNull($trail->user_id);
        $this->assertSame('SHIPPED', $trail->valuesOf('old_values')['status']);
        $this->assertSame('COMPLETED', $trail->valuesOf('new_values')['status']);
        $this->assertSame('ORD-AC-OLD', $trail->valuesOf('new_values')['order_number']);

        // Riwayat order menampilkan perubahan otomatis
        $this->actingAs($this->userWithRole('OWNER'))->get(route('gudang.outbound.show', $old->id))->assertOk()
            ->assertSee('Otomatis: lebih dari 3 hari sejak dikirim')->assertSee('Sistem');

        // Dijalankan lagi tidak mengubah apa-apa; setelah lewat 3 hari, order kedua ikut selesai
        $this->artisan('wms:selesaikan-order')->expectsOutputToContain('0 order');
        Carbon::setTestNow('2026-11-05 11:30:00');
        $this->assertSame(1, OrderAutoComplete::run());
        $this->assertSame('COMPLETED', $fresh->fresh()->status);
    }

    public function test_days_come_from_settings_and_zero_turns_it_off(): void
    {
        $this->makeSku('JSC-A-L');
        Carbon::setTestNow('2026-11-05 10:00:00');
        $order = $this->sentOrder('ORD-AC-SET', '2026-11-03 09:00:00');

        $this->actingAs($this->userWithRole('OWNER'));
        $this->get(route('settings.index'))->assertOk()
            ->assertSee('Order Dikirim otomatis Selesai setelah (hari)')->assertSee('value="3"', false)
            ->assertSee('Pengecekan terakhir: belum pernah');

        $this->put(route('settings.update'), [
            'company_name' => 'JERSIC', 'low_stock_threshold' => 5, 'order_auto_complete_days' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, OrderAutoComplete::days());
        $this->artisan('wms:selesaikan-order')->expectsOutputToContain('dimatikan');
        $this->assertSame('SHIPPED', $order->fresh()->status);

        $this->put(route('settings.update'), [
            'company_name' => 'JERSIC', 'low_stock_threshold' => 5, 'order_auto_complete_days' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, OrderAutoComplete::run());
        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->get(route('settings.index'))->assertSee('(1 order diselesaikan)');

        $this->put(route('settings.update'), [
            'company_name' => 'JERSIC', 'low_stock_threshold' => 5, 'order_auto_complete_days' => 999,
        ])->assertSessionHasErrors('order_auto_complete_days');
    }

    public function test_command_is_scheduled_and_connected_agent_runs_it_without_cron(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'wms:selesaikan-order'));
        $this->assertNotNull($event);
        $this->assertSame('5 * * * *', $event->expression);

        $this->makeSku('JSC-A-L');
        Carbon::setTestNow('2026-11-05 10:00:00');
        $order = $this->sentOrder('ORD-AC-AGN', '2026-11-01 09:00:00');
        $token = WhatsApp::generateToken();
        Cache::forget('whatsapp-schedule-tick');

        $this->postJson(route('api.whatsapp.agent'), ['state' => 'connected'], ['Authorization' => 'Bearer ' . $token])->assertOk();

        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertSame('agen', OrderAutoComplete::lastRun()['trigger']);
    }

    public function test_return_with_matching_resi_turns_completed_order_into_returned(): void
    {
        $staff = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 1);
        Carbon::setTestNow('2026-11-05 10:00:00');
        $order = $this->sentOrder('ORD-AC-RTR', '2026-10-28 09:00:00', resi: 'JX0099887766');
        OrderAutoComplete::run();
        $this->assertSame('COMPLETED', $order->fresh()->status);

        // Paket retur datang lebih dari 3 hari kemudian: dicari lewat no. resi (huruf kecil juga cocok)
        $this->actingAs($staff)->get(route('gudang.retur.create', ['order' => 'jx0099887766']))
            ->assertOk()->assertSee('ORD-AC-RTR');

        $this->post(route('gudang.retur.store'), [
            'order_id' => $order->id,
            'reason' => 'RUSAK',
            'items' => [$order->items[0]->id => ['qty_good' => 1, 'qty_damaged' => 0, 'bin_code' => 'A-01-01']],
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('RETURNED', $order->status);
        $this->assertSame('Diretur', $order->status_label);
        $this->assertFalse($order->isEditable());
        $return = OrderReturn::firstOrFail();

        $trail = AuditTrail::where('module', 'OUTBOUND')->where('action', 'UPDATE_STATUS')->latest('id')->firstOrFail();
        $this->assertSame('COMPLETED', $trail->valuesOf('old_values')['status']);
        $this->assertSame('RETURNED', $trail->valuesOf('new_values')['status']);

        $this->get(route('gudang.outbound.show', $order->id))->assertOk()->assertSee('Diretur')
            ->assertSee("Retur {$return->return_number} diterima (resi JX0099887766)");
        $this->get(route('gudang.outbound', ['status' => 'RETURNED']))->assertOk()->assertSee('ORD-AC-RTR');

        // Retur susulan untuk sisa barang tetap bisa
        $this->get(route('gudang.retur.create', ['order' => 'ORD-AC-RTR']))->assertOk()->assertSee('ORD-AC-RTR');

        // Diretur tidak disentuh lagi oleh pengecekan otomatis
        OrderAutoComplete::run();
        $this->assertSame('RETURNED', $order->fresh()->status);

        // Retur salah input dihapus: status kembali ke Selesai
        $this->delete(route('gudang.retur.destroy', $return->id))->assertSessionHasNoErrors();
        $this->assertSame('COMPLETED', $order->fresh()->status);
    }

    public function test_return_on_shipped_order_within_three_days_also_becomes_returned_and_never_auto_completes(): void
    {
        $this->makeSku('JSC-A-L');
        Carbon::setTestNow('2026-11-05 10:00:00');
        $order = $this->sentOrder('ORD-AC-RT2', '2026-11-04 09:00:00', resi: 'JX-2');

        $this->actingAs($this->userWithRole('STAFF_GUDANG'))->post(route('gudang.retur.store'), [
            'order_id' => $order->id,
            'reason' => 'SALAH_KIRIM',
            'items' => [$order->items[0]->id => ['qty_good' => 0, 'qty_damaged' => 2]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('RETURNED', $order->fresh()->status);

        Carbon::setTestNow('2026-11-10 10:00:00');
        OrderAutoComplete::run();
        $this->assertSame('RETURNED', $order->fresh()->status);

        // Semua barang sudah kembali: order Diretur bisa diproses ulang dengan resi yang sama
        $this->actingAs($this->userWithRole('OWNER'))->get(route('gudang.outbound.show', $order->id))->assertOk()->assertSee('Proses ulang');
    }

    public function test_returned_status_and_default_days(): void
    {
        $this->assertContains('RETURNED', Order::SENT_STATUSES);
        $this->assertSame('Diretur', Order::STATUS_LABELS['RETURNED']);
        $this->assertSame(3, OrderAutoComplete::days());
        SystemSetting::set('order_auto_complete_days', '');
        $this->assertSame(3, OrderAutoComplete::days());
    }
}
