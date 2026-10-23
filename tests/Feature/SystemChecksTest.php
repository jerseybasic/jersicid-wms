<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Models\SystemSetting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Hasil pemeriksaan ulang: zona waktu WIB, cek stok saat konfirmasi order,
 * pesan validasi bahasa Indonesia, dan backup terjadwal.
 */
class SystemChecksTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function pendingOrder(string $number, string $sku, int $qty, string $status = 'PENDING', int $picked = 0): Order
    {
        $order = Order::create(['order_number' => $number, 'customer_name' => 'Budi', 'status' => $status]);
        OrderItem::create(['order_id' => $order->id, 'sku' => $sku, 'qty_ordered' => $qty, 'qty_picked' => $picked, 'price' => 0]);

        return $order;
    }

    public function test_app_runs_in_wib_with_indonesian_messages(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
        $this->assertSame('id', app()->getLocale());

        $this->actingAs($this->userWithRole('STAFF_GUDANG'))
            ->from(route('gudang.transfer'))
            ->post(route('gudang.transfer.store'), [])
            ->assertSessionHasErrors(['bin_from' => 'rak asal wajib diisi.']);
    }

    public function test_order_cannot_be_confirmed_without_enough_free_stock(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 3);
        $this->putStock('JSC-A-L', 'A-01-02', 2);

        // 5 pcs di rak: 2 sudah dipesan order lain yang belum diambil, 1 dari order yang sedang dipicking
        $this->pendingOrder('ORD-LAIN', 'JSC-A-L', 2, 'CONFIRMED');
        $this->pendingOrder('ORD-PICK', 'JSC-A-L', 3, 'PICKING', 2);
        $this->pendingOrder('ORD-KIRIM', 'JSC-A-L', 9, 'SHIPPED', 9);    // sudah keluar, tidak dihitung

        $tooMuch = $this->pendingOrder('ORD-BARU', 'JSC-A-L', 3);
        $this->actingAs($staff)->patch(route('gudang.outbound.update-status', $tooMuch->id), ['status' => 'CONFIRMED'])
            ->assertSessionHas('error', 'Order ORD-BARU belum bisa dikonfirmasi, stok kurang: JSC-A-L butuh 3, tersedia 2.');
        $this->assertSame('PENDING', $tooMuch->fresh()->status);

        $fits = $this->pendingOrder('ORD-PAS', 'JSC-A-L', 2);
        $this->patch(route('gudang.outbound.update-status', $fits->id), ['status' => 'CONFIRMED'])
            ->assertSessionHas('success');
        $this->assertSame('CONFIRMED', $fits->fresh()->status);
    }

    public function test_legacy_utc_timestamps_are_shifted_once(): void
    {
        $this->makeSku('JSC-A-L');
        $movement = StockMovement::create(['sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'type' => 'INBOUND', 'qty' => 1]);
        DB::table('stock_movements')->where('id', $movement->id)
            ->update(['created_at' => '2026-10-20 18:30:00', 'updated_at' => '2026-10-20 18:30:00']);

        $this->artisan('wms:geser-waktu-lama', ['--force' => true, '--sebelum' => '2026-10-21 00:00:00'])->assertSuccessful();
        $this->assertSame('2026-10-21 01:30:00', DB::table('stock_movements')->where('id', $movement->id)->value('created_at'));
        $this->assertNotNull(SystemSetting::get('timestamps_shifted_to_local'));

        // Dijalankan lagi: tidak menggeser dua kali
        $this->artisan('wms:geser-waktu-lama', ['--force' => true, '--sebelum' => '2026-10-22 00:00:00'])->assertSuccessful();
        $this->assertSame('2026-10-21 01:30:00', DB::table('stock_movements')->where('id', $movement->id)->value('created_at'));
    }

    public function test_daily_database_backup_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->pluck('command')->implode("\n");

        $this->assertStringContainsString('backup:run --only-db', $commands);
        $this->assertStringContainsString('backup:clean', $commands);
    }
}
