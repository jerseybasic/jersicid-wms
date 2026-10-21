<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Label Kirim {{ $order->order_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #e2e8f0; }
        .toolbar { position: sticky; top: 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 12px 16px; background: #0f172a; color: #fff; font-size: 14px; }
        .toolbar a, .toolbar button { padding: 10px 14px; border-radius: 8px; border: 0; font-size: 14px; font-weight: bold; text-decoration: none; cursor: pointer; }
        .btn-print { background: #06b6d4; color: #0f172a; }
        .btn-plain { background: #334155; color: #fff; }
        .page { padding: 16px; }
        .label { width: 100mm; min-height: 150mm; margin: 0 auto; background: #fff; border: 0.4mm solid #000; padding: 4mm; display: flex; flex-direction: column; gap: 3mm; font-size: 9pt; }
        .row { display: flex; justify-content: space-between; align-items: center; gap: 3mm; }
        .courier { font-size: 18pt; font-weight: bold; text-transform: uppercase; }
        .box { border: 0.3mm solid #000; padding: 2.5mm; }
        .caption { font-size: 7pt; text-transform: uppercase; color: #333; }
        .to-name { font-size: 14pt; font-weight: bold; }
        .address { font-size: 11pt; line-height: 1.3; white-space: pre-line; }
        .tracking { text-align: center; }
        .tracking svg { width: 32mm; height: 32mm; }
        .tracking .number { font-family: "Courier New", monospace; font-size: 13pt; font-weight: bold; letter-spacing: 0.5px; }
        table { width: 100%; border-collapse: collapse; font-size: 8pt; }
        td { padding: 0.8mm 0; border-bottom: 0.2mm dotted #999; }
        td.qty { text-align: right; font-weight: bold; white-space: nowrap; }
        .warning { text-align: center; font-weight: bold; font-size: 8pt; border-top: 0.3mm solid #000; padding-top: 2mm; }

        @page { size: 100mm 150mm; margin: 0; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .page { padding: 0; }
            .label { border: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <strong>Label kirim {{ $order->order_number }}</strong>
        <button class="btn-print" onclick="window.print()">Cetak</button>
        <a class="btn-plain" href="{{ url()->previous() }}">Kembali</a>
    </div>

    @php($code = $order->tracking_number ?: $order->order_number)
    <div class="page">
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
    </div>

    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
</body>
</html>
