<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #e2e8f0; }
        .toolbar { position: sticky; top: 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 12px 16px; background: #0f172a; color: #fff; font-size: 14px; }
        .toolbar a, .toolbar button { padding: 10px 14px; border-radius: 8px; border: 0; font-size: 14px; font-weight: bold; text-decoration: none; cursor: pointer; }
        .btn-print { background: #06b6d4; color: #0f172a; }
        .btn-plain { background: #334155; color: #fff; }
        .btn-plain.active { background: #fff; color: #0f172a; }
        .note { flex-basis: 100%; color: #fbbf24; }
        .sheet { display: flex; flex-wrap: wrap; gap: 8px; padding: 16px; }
        .label { width: 50mm; height: 30mm; background: #fff; display: flex; gap: 2mm; padding: 2mm; overflow: hidden; }
        .label .qr svg { width: 22mm; height: 22mm; display: block; }
        .label .info { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
        .label .sku { font-family: "Courier New", monospace; font-weight: bold; font-size: 8pt; word-break: break-all; }
        .label .name { font-size: 7pt; line-height: 1.15; max-height: 3.5em; overflow: hidden; }
        .label .size { font-size: 12pt; font-weight: bold; margin-top: 1mm; }
        .label .bin { font-size: 6.5pt; }

        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { padding: 0; gap: 0; }
        }
        @if ($size === 'a4')
            @page { size: A4; margin: 8mm; }
            @media print { .sheet { gap: 2mm; } .label { border: 0.2mm dashed #999; } }
        @else
            @page { size: 50mm 30mm; margin: 0; }
            @media print { .label { page-break-after: always; break-after: page; } }
        @endif
    </style>
</head>
<body>
    <div class="toolbar">
        <strong>{{ $title }}</strong>
        <span>{{ count($labels) }} label</span>
        <a class="btn-plain {{ $size === 'stiker' ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['ukuran' => 'stiker']) }}">Stiker 50×30</a>
        <a class="btn-plain {{ $size === 'a4' ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['ukuran' => 'a4']) }}">Kertas A4</a>
        <button class="btn-print" onclick="window.print()">Cetak</button>
        <a class="btn-plain" href="{{ url()->previous() }}">Kembali</a>
        @if ($truncated)
            <span class="note">Total {{ $total }} pcs, yang ditampilkan hanya {{ count($labels) }} label pertama. Cetak sisanya dari menu Thermal Printer.</span>
        @endif
        @if (!empty($missing))
            <span class="note">SKU tidak ditemukan dan dilewati: {{ implode(', ', $missing) }}</span>
        @endif
    </div>

    @php($qrCache = [])
    <div class="sheet">
        @foreach ($labels as $label)
            @php($code = $label['sku']->sku)
            @php($qrCache[$code] ??= (string) \SimpleSoftwareIO\QrCode\Facades\QrCode::size(90)->margin(0)->generate($code))
            <div class="label">
                <div class="qr">{!! $qrCache[$code] !!}</div>
                <div class="info">
                    <div class="sku">{{ $code }}</div>
                    <div class="name">{{ $label['sku']->product_name }} {{ $label['sku']->version }}</div>
                    <div class="size">{{ $label['sku']->size }}</div>
                    @if ($label['bin'])
                        <div class="bin">Bin {{ $label['bin'] }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
</body>
</html>
