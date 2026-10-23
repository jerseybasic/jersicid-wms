<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @php
        // [lebar, tinggi] label dalam mm; a4 = kartu 95×48 mm di kertas A4
        $dims = ['stiker' => [50, 30], 'sedang' => [100, 50], 'besar' => [100, 150], 'a4' => [95, 48]][$size];
        $names = ['stiker' => 'Stiker 50×30', 'sedang' => 'Label 100×50', 'besar' => 'Label 100×150', 'a4' => 'Kertas A4'];
        $tall = $size === 'besar';
        $qrMm = $tall ? 80 : $dims[1] - 6;
    @endphp
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
        .label { width: {{ $dims[0] }}mm; height: {{ $dims[1] }}mm; background: #fff; overflow: hidden; display: flex; align-items: center; gap: 2.5mm; padding: 2mm 3mm; }
        .label .qr svg { width: {{ $qrMm }}mm; height: {{ $qrMm }}mm; display: block; }
        .label .info { flex: 1; min-width: 0; }
        .label .title { font-weight: 900; line-height: 1.05; word-break: break-word; font-size: {{ ['stiker' => '11pt', 'sedang' => '22pt', 'besar' => '26pt', 'a4' => '20pt'][$size] }}; }
        .label .sub { margin-top: 1.5mm; line-height: 1.2; font-size: {{ ['stiker' => '5.5pt', 'sedang' => '8pt', 'besar' => '11pt', 'a4' => '8pt'][$size] }}; }
        .label .code { margin-top: 1mm; font-family: "Courier New", monospace; font-size: {{ $size === 'stiker' ? '6pt' : '9pt' }}; }
        .label.tall { flex-direction: column; justify-content: center; text-align: center; padding: 6mm; gap: 5mm; }
        .label.tall .info { flex: 0 0 auto; }

        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { padding: 0; gap: 0; }
        }
        @if ($size === 'a4')
            @page { size: A4; margin: 8mm; }
            @media print { .sheet { gap: 2mm; } .label { border: 0.2mm dashed #999; break-inside: avoid; } }
        @else
            @page { size: {{ $dims[0] }}mm {{ $dims[1] }}mm; margin: 0; }
            @media print { .label { page-break-after: always; break-after: page; } }
        @endif
    </style>
</head>
<body>
    <div class="toolbar">
        <strong>{{ $title }}</strong>
        <span>{{ count($labels) }} label</span>
        @foreach ($sizes as $option)
            <a class="btn-plain {{ $size === $option ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['ukuran' => $option]) }}">{{ $names[$option] }}</a>
        @endforeach
        <button class="btn-print" onclick="window.print()">Cetak</button>
        <a class="btn-plain" href="{{ route('gudang.printer') }}">Kembali</a>
        @if ($truncated)
            <span class="note">Total {{ $total }} label, yang ditampilkan hanya {{ count($labels) }} label pertama.</span>
        @endif
    </div>

    <div class="sheet">
        @foreach ($labels as $label)
            <div class="label {{ $tall ? 'tall' : '' }}">
                @if ($tall)
                    <div class="info"><div class="title">{{ $label['title'] }}</div></div>
                @endif
                <div class="qr">{!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(200)->margin(0)->errorCorrection('M')->generate($label['code']) !!}</div>
                <div class="info">
                    @unless ($tall)
                        <div class="title">{{ $label['title'] }}</div>
                    @endunless
                    @if ($label['title'] !== $label['code'])
                        <div class="code">{{ $label['code'] }}</div>
                    @endif
                    <div class="sub">{{ $label['sub'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
</body>
</html>
