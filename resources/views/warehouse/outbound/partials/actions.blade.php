{{-- Tombol aksi order sesuai statusnya (dipakai di daftar Outbound dan detail order) --}}
@if($order->status === 'PENDING')
    <form method="POST" action="{{ route('gudang.outbound.update-status', $order->id) }}">
        @csrf @method('PATCH')
        <input type="hidden" name="status" value="CONFIRMED">
        <button class="px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white text-xs rounded font-medium">Konfirmasi</button>
    </form>
@elseif($order->status === 'CONFIRMED')
    <a href="{{ route('gudang.picking.create') }}" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-500 text-white text-xs rounded font-medium">Buat Picking</a>
@elseif($order->status === 'PICKING')
    <a href="{{ route('gudang.picking') }}" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white text-xs rounded font-medium">Lihat Picking</a>
@elseif($order->status === 'PACKING')
    <a href="{{ route('gudang.packing') }}" class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 text-white text-xs rounded font-medium">Ke Packing</a>
@elseif($order->status === 'READY_TO_SHIP')
    {{-- Dikirim hanya lewat serah terima kurir; di sini cukup lengkapi resinya --}}
    @if(blank($order->tracking_number))
        <a href="{{ route('gudang.outbound.edit', $order->id) }}" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-500 text-white text-xs rounded font-medium">Isi resi</a>
    @endif
    <a href="{{ route('gudang.handover') }}" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs rounded font-medium">Serah terima</a>
@elseif($order->status === 'SHIPPED')
    <form method="POST" action="{{ route('gudang.outbound.update-status', $order->id) }}">
        @csrf @method('PATCH')
        <input type="hidden" name="status" value="COMPLETED">
        <button class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white text-xs rounded font-medium">Selesai</button>
    </form>
@endif
@if(in_array($order->status, ['READY_TO_SHIP', 'SHIPPED', 'COMPLETED']))
    <a href="{{ route('gudang.label.shipping', $order->id) }}" target="_blank" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white text-xs rounded font-medium">Label</a>
@endif
@if(in_array($order->status, ['SHIPPED', 'COMPLETED']))
    <a href="{{ route('gudang.retur.create', ['order' => $order->order_number]) }}" class="px-3 py-1.5 bg-slate-700 hover:bg-orange-600 text-white text-xs rounded font-medium">Retur</a>
@endif
@if(in_array($order->status, ['PENDING', 'CONFIRMED']))
    <form method="POST" action="{{ route('gudang.outbound.update-status', $order->id) }}" onsubmit="return confirm('Batalkan order ini?')">
        @csrf @method('PATCH')
        <input type="hidden" name="status" value="CANCELLED">
        <button class="px-3 py-1.5 bg-red-600/80 hover:bg-red-500 text-white text-xs rounded font-medium">Batal</button>
    </form>
@endif
