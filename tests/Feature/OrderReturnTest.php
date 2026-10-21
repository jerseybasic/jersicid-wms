<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Retur dari pelanggan: barang bagus kembali ke rak, rusak hanya dicatat,
 * dan tidak bisa meretur lebih dari yang dikirim.
 */
class OrderReturnTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function shippedOrder(string $status = 'SHIPPED'): Order
    {
        $order = Order::create([
            'order_number' => 'ORD-20261021-RT01',
            'customer_name' => 'Budi',
            'courier' => 'JNE',
            'tracking_number' => 'JNE123456',
            'status' => $status,
            'shipped_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_ordered' => 3, 'price' => 150000]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'JSC-B-L', 'bin_code' => null, 'qty_ordered' => 1, 'price' => 150000]);

        return $order->load('items');
    }

    public function test_staff_receives_return_good_items_go_back_to_rack(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-B-L');
        $this->putStock('JSC-A-L', 'A-01-01', 2);
        $this->putStock('JSC-B-L', 'B-02-01', 7);
        $order = $this->shippedOrder();
        [$itemA, $itemB] = [$order->items[0], $order->items[1]];

        // Cari lewat no. resi (QR di label kirim); rak disarankan dari rak asal picking / rak dengan stok terbanyak
        $this->actingAs($staff)->get(route('gudang.retur.create', ['order' => 'JNE123456']))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('value="A-01-01"', false)
            ->assertSee('value="B-02-01"', false);

        $response = $this->post(route('gudang.retur.store'), [
            'order_id' => $order->id,
            'reason' => 'TUKAR_UKURAN',
            'notes' => 'Minta XL',
            'items' => [
                $itemA->id => ['qty_good' => 2, 'qty_damaged' => 1, 'bin_code' => 'a-01-01'],
                $itemB->id => ['qty_good' => 0, 'qty_damaged' => 0, 'bin_code' => 'B-02-01'],
            ],
        ]);

        $return = OrderReturn::firstOrFail();
        $response->assertRedirect(route('gudang.retur.show', $return->id));
        $this->assertSame(4, $this->stockQty('JSC-A-L', 'A-01-01'));   // 2 + 2 bagus, yang rusak tidak masuk
        $this->assertSame(7, $this->stockQty('JSC-B-L', 'B-02-01'));
        $this->assertSame(1, $return->items()->count());

        $movement = StockMovement::where('reference_no', $return->return_number)->firstOrFail();
        $this->assertSame('RETURN', $movement->type);
        $this->assertSame(2, $movement->qty);

        $this->get(route('gudang.retur.show', $return->id))->assertOk()->assertSee('Tukar ukuran')->assertSee('Minta XL');
        $this->get(route('gudang.retur'))->assertOk()->assertSee($return->return_number);
        $this->get(route('gudang.label.sku', ['ref' => $return->return_number]))->assertOk();

        // Sisa yang bisa diretur untuk item A tinggal 0, item B masih 1
        $this->post(route('gudang.retur.store'), [
            'order_id' => $order->id,
            'reason' => 'RUSAK',
            'items' => [$itemA->id => ['qty_good' => 1, 'bin_code' => 'A-01-01']],
        ])->assertSessionHasErrors("items.{$itemA->id}.qty_good");
        $this->assertSame(1, OrderReturn::count());
        $this->assertSame(4, $this->stockQty('JSC-A-L', 'A-01-01'));
    }

    public function test_return_rules(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-B-L');
        $order = $this->shippedOrder();

        // Barang bagus wajib punya lokasi rak
        $this->actingAs($staff)->post(route('gudang.retur.store'), [
            'order_id' => $order->id,
            'reason' => 'LAINNYA',
            'items' => [$order->items[0]->id => ['qty_good' => 1, 'bin_code' => '']],
        ])->assertSessionHasErrors("items.{$order->items[0]->id}.bin_code");

        // Minimal 1 pcs
        $this->post(route('gudang.retur.store'), [
            'order_id' => $order->id,
            'reason' => 'LAINNYA',
            'items' => [$order->items[0]->id => ['qty_good' => 0, 'qty_damaged' => 0]],
        ])->assertSessionHasErrors('items');

        // Hanya rusak: tercatat tanpa menambah stok
        $this->post(route('gudang.retur.store'), [
            'order_id' => $order->id,
            'reason' => 'RUSAK',
            'items' => [$order->items[1]->id => ['qty_damaged' => 1]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, StockMovement::where('type', 'RETURN')->count());

        // Order yang belum dikirim belum bisa diretur
        $order->update(['status' => 'PACKING']);
        $this->get(route('gudang.retur.create', ['order' => $order->order_number]))
            ->assertRedirect(route('gudang.retur.create'))
            ->assertSessionHas('error');
        $this->post(route('gudang.retur.store'), [
            'order_id' => $order->id,
            'reason' => 'RUSAK',
            'items' => [$order->items[0]->id => ['qty_damaged' => 1]],
        ])->assertStatus(422);

        $this->get(route('gudang.retur.create', ['order' => 'ORD-TIDAK-ADA']))->assertSessionHas('error');
    }
}
