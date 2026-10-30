{{-- Tombol aksi order sesuai statusnya (dipakai di daftar Outbound dan detail order) --}}
@if($order->status === 'PENDING')
    <form method="POST" action="{{ route('gudang.outbound.update-status', $order->id) }}">
        @csrf @method('PATCH')
        <input type="hidden" name="status" value="CONFIRMED">
        <button class="px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white text-xs rounded font-medium">{{ $order->isWaitingStock() ? 'Cek stok lagi' : 'Konfirmasi' }}</button>
    </form>
@elseif($order->status === 'CONFIRMED')
    {{-- Langsung ke layar scan; untuk beberapa order sekaligus, centang lalu Picking gabungan --}}
    <form method="POST" action="{{ route('gudang.picking.store') }}">
        @csrf
        <input type="hidden" name="order_id" value="{{ $order->id }}">
        <input type="hidden" name="start_now" value="1">
        <button class="px-3 py-1.5 bg-purple-600 hover:bg-purple-500 text-white text-xs rounded font-medium">Mulai Picking</button>
    </form>
@elseif($order->status === 'PICKING')
    @php $picking = $order->pickingSession; @endphp
    <a href="{{ $picking?->batch_id ? route('gudang.picking-batch.show', $picking->batch_id) : ($picking ? route('gudang.picking.show', $picking->id) : route('gudang.picking')) }}" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white text-xs rounded font-medium">Lihat Picking</a>
@elseif($order->status === 'PACKING')
    @php $packing = $order->packingSession; @endphp
    @if ($packing)
        <a href="{{ $packing->packing_batch_id ? route('gudang.packing-batch.show', $packing->packing_batch_id) : route('gudang.packing.show', $packing->id) }}" class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 text-white text-xs rounded font-medium">Lanjut Packing</a>
    @else
        <form method="POST" action="{{ route('gudang.packing.store') }}">
            @csrf
            <input type="hidden" name="order_id" value="{{ $order->id }}">
            <input type="hidden" name="courier" value="{{ $order->courier }}">
            <button class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 text-white text-xs rounded font-medium">Mulai Packing</button>
        </form>
    @endif
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
@if(in_array($order->status, \App\Models\Order::IN_PROCESS_STATUSES) && in_array(auth()->user()->role, \App\Services\OrderCancellation::ROLES))
    {{-- Dibatalkan pembeli/marketplace saat sudah diproses: barangnya masuk daftar Kembalikan ke Rak --}}
    <form method="POST" action="{{ route('gudang.outbound.cancel', $order->id) }}"
        onsubmit="const r = prompt(@js('Batalkan order ' . $order->order_number . ' yang sedang diproses? Barang yang sudah diambil harus dikembalikan ke rak.' . "\n\n" . 'Alasan (boleh kosong):')); if (r === null) return false; this.reason.value = r; return true;">
        @csrf
        <input type="hidden" name="reason">
        <button class="px-3 py-1.5 bg-red-600/80 hover:bg-red-500 text-white text-xs rounded font-medium">Batal</button>
    </form>
@endif
@if(in_array($order->status, ['SHIPPED', 'COMPLETED']) && in_array(auth()->user()->role, \App\Services\OrderReprocess::ROLES))
    @php [$reprocessTo, $reprocessError] = \App\Services\OrderReprocess::target($order); @endphp
    @if ($reprocessTo)
        {{-- Kirim ulang dengan resi yang sama, tanpa membuat order baru --}}
        <form method="POST" action="{{ route('gudang.outbound.reprocess', $order->id) }}"
            onsubmit="return confirm(@js($reprocessTo === 'READY_TO_SHIP'
                ? 'Kembalikan order ' . $order->order_number . ' ke Siap Kirim? Resinya tetap sama dan bisa di-scan lagi di Serah Terima.'
                : 'Proses ulang order ' . $order->order_number . '? Barangnya sudah kembali lewat retur, jadi order masuk antrean picking lagi dengan resi yang sama.'))">
            @csrf
            <button class="px-3 py-1.5 bg-amber-600 hover:bg-amber-500 text-white text-xs rounded font-medium">Proses ulang</button>
        </form>
    @endif
@endif
@if($order->status === 'CANCELLED' && in_array(auth()->user()->role, \App\Services\OrderCancellation::ROLES))
    {{-- Batal karena salah klik atau uji coba: order kembali Pending seperti order baru --}}
    <form method="POST" action="{{ route('gudang.outbound.reactivate', $order->id) }}"
        onsubmit="return confirm(@js('Aktifkan lagi order ' . $order->order_number . '? Order kembali Pending seperti order baru, lalu bisa dikonfirmasi dan diproses lagi.'))">
        @csrf
        <button class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 text-white text-xs rounded font-medium">Aktifkan lagi</button>
    </form>
@endif
