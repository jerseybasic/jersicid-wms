<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manifest {{ $handover->courier }} {{ $handover->handover_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #e2e8f0; }
        .toolbar { position: sticky; top: 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 12px 16px; background: #0f172a; color: #fff; font-size: 14px; }
        .toolbar a, .toolbar button { padding: 10px 14px; border-radius: 8px; border: 0; font-size: 14px; font-weight: bold; text-decoration: none; cursor: pointer; }
        .btn-print { background: #06b6d4; color: #0f172a; }
        .btn-plain { background: #334155; color: #fff; }
        .notice { margin: 16px auto 0; max-width: 210mm; padding: 10px 14px; border-radius: 8px; background: #d1fae5; color: #065f46; font-size: 14px; }
        .sheet { max-width: 210mm; margin: 16px auto; background: #fff; padding: 14mm 12mm; font-size: 10pt; }
        h1 { margin: 0; font-size: 16pt; }
        .meta { display: grid; grid-template-columns: repeat(2, 1fr); gap: 2mm 8mm; margin: 5mm 0; }
        .meta div span { display: block; font-size: 8pt; color: #444; text-transform: uppercase; }
        .meta div strong { font-size: 11pt; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 0.3mm solid #000; padding: 1.8mm 2mm; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-size: 8.5pt; text-transform: uppercase; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .mono { font-family: "Courier New", monospace; font-weight: bold; }
        .total td { font-weight: bold; }
        .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 12mm; margin-top: 10mm; text-align: center; }
        .sign .line { margin-top: 22mm; min-height: 6mm; border-top: 0.3mm solid #000; padding-top: 1.5mm; }
        @page { size: A4; margin: 10mm; }
        @media print {
            body { background: #fff; }
            .toolbar, .notice { display: none; }
            .sheet { margin: 0; padding: 0; max-width: none; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <strong>Manifest {{ $handover->courier }}</strong>
        <button class="btn-print" onclick="window.print()">Cetak</button>
        <a class="btn-plain" href="{{ route('gudang.handover') }}">Serah Terima Kurir</a>
    </div>

    @if (session('success'))
        <div class="notice">{{ session('success') }}</div>
    @endif

    <div class="sheet">
        <h1>Manifest Serah Terima Paket</h1>
        <div class="meta">
            <div><span>Kurir</span><strong>{{ $handover->courier }}</strong></div>
            <div><span>No. serah terima</span><strong>{{ $handover->handover_number }}</strong></div>
            <div><span>Waktu diserahkan</span><strong>{{ $handover->completed_at?->format('d/m/Y H:i') ?? 'Belum diserahkan' }}</strong></div>
            <div><span>Jumlah paket</span><strong>{{ $handover->orders->count() }} paket · {{ (int) $handover->orders->sum('items_sum_qty_ordered') }} pcs</strong></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="num">No</th>
                    <th>Nomor resi</th>
                    <th>Order</th>
                    <th>Penerima</th>
                    <th class="num">Pcs</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($handover->orders as $order)
                    <tr>
                        <td class="num">{{ $loop->iteration }}</td>
                        <td class="mono">{{ $order->tracking_number }}</td>
                        <td>{{ $order->order_number }}</td>
                        <td>{{ $order->customer_name }}</td>
                        <td class="num">{{ (int) $order->items_sum_qty_ordered }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="4">Total</td>
                    <td class="num">{{ (int) $handover->orders->sum('items_sum_qty_ordered') }}</td>
                </tr>
            </tbody>
        </table>

        <div class="sign">
            <div>Diserahkan oleh (gudang)<div class="line">{{ $handover->user?->name }}</div></div>
            <div>Diterima oleh (kurir {{ $handover->courier }})<div class="line">{{ $handover->driver_name ?: ' ' }}</div></div>
        </div>
    </div>

    @if (session('success'))
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
    @endif
</body>
</html>
