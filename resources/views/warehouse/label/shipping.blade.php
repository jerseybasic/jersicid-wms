<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Label Kirim {{ $orders->count() === 1 ? $orders->first()->order_number : $orders->count() . ' order' }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #e2e8f0; }
        .toolbar { position: sticky; top: 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 12px 16px; background: #0f172a; color: #fff; font-size: 14px; }
        .toolbar a, .toolbar button { padding: 10px 14px; border-radius: 8px; border: 0; font-size: 14px; font-weight: bold; text-decoration: none; cursor: pointer; }
        .btn-print { background: #06b6d4; color: #0f172a; }
        .btn-plain { background: #334155; color: #fff; }
        .page { padding: 16px; }
        .label { width: 100mm; min-height: 150mm; margin: 0 auto 16px; break-after: page; background: #fff; border: 0.4mm solid #000; padding: 4mm; display: flex; flex-direction: column; gap: 3mm; font-size: 9pt; }
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
            .label { border: 0; margin: 0; }
            .label:last-child { break-after: auto; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <strong>Label kirim {{ $orders->count() === 1 ? $orders->first()->order_number : $orders->count() . ' order' }}</strong>
        <button class="btn-print" onclick="window.print()">Cetak</button>
        <a class="btn-plain" href="{{ url()->previous() }}">Kembali</a>
    </div>

    <div class="page">
        @foreach ($orders as $order)
            @include('warehouse.label._shipping-label', ['order' => $order])
        @endforeach
    </div>

    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
</body>
</html>
