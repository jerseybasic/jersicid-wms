{{-- Satu label kirim 100x150 mm. Dipakai untuk cetak satuan dan massal. --}}
@php($code = $order->tracking_number ?: $order->order_number)
<div class="label">
    <div class="row">
        <div class="courier">{{ $order->courier ?: 'Kurir' }}</div>
        <div style="text-align:right">
            <div class="caption">No. Order</div>
            <strong>{{ $order->order_number }}</strong>
        </div>
    </div>

    <div class="tracking box">
        {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(120)->margin(0)->generate($code) !!}
        <div class="caption">{{ $order->tracking_number ? 'No. Resi' : 'Resi belum diisi, scan no. order' }}</div>
        <div class="number">{{ $code }}</div>
    </div>

    <div class="box">
        <div class="caption">Penerima</div>
        <div class="to-name">{{ $order->customer_name }}</div>
        @if ($order->customer_phone)
            <div><strong>{{ $order->customer_phone }}</strong></div>
        @endif
        <div class="address">{{ $order->shipping_address }}</div>
    </div>

    <div class="box">
        <div class="caption">Pengirim</div>
        <strong>{{ $senderName }}</strong>
        @if ($senderPhone)
            <span> · {{ $senderPhone }}</span>
        @endif
        @if ($senderAddress)
            <div>{{ $senderAddress }}</div>
        @endif
    </div>

    <table>
        @foreach ($order->items as $item)
            <tr>
                <td>{{ $item->sku }} <span style="color:#444">{{ $item->masterSku?->product_name }}</span></td>
                <td class="qty">× {{ $item->qty_ordered }}</td>
            </tr>
        @endforeach
    </table>

    <div class="warning">WAJIB VIDEO UNBOXING SAAT MEMBUKA PAKET</div>
</div>
