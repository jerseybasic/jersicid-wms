<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PO {{ $po->po_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #e2e8f0; }
        .toolbar { position: sticky; top: 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 12px 16px; background: #0f172a; color: #fff; font-size: 14px; }
        .toolbar a, .toolbar button { padding: 10px 14px; border-radius: 8px; border: 0; font-size: 14px; font-weight: bold; text-decoration: none; cursor: pointer; }
        .btn-print { background: #06b6d4; color: #0f172a; }
        .btn-wa { background: #22c55e; color: #052e16; }
        .btn-plain { background: #334155; color: #fff; }
        .hint { font-size: 12px; color: #94a3b8; }
        .sheet { position: relative; max-width: 210mm; margin: 16px auto; background: #fff; padding: 14mm 12mm; font-size: 10pt; }
        .head { display: flex; justify-content: space-between; gap: 8mm; border-bottom: 0.5mm solid #000; padding-bottom: 4mm; }
        .head h1 { margin: 0; font-size: 16pt; }
        .head .company { font-size: 9pt; color: #333; }
        .head .doc { text-align: right; }
        .head .doc strong { display: block; font-size: 13pt; }
        .meta { display: grid; grid-template-columns: repeat(2, 1fr); gap: 2mm 8mm; margin: 5mm 0; }
        .meta div span { display: block; font-size: 8pt; color: #444; text-transform: uppercase; }
        .meta div strong { font-size: 10.5pt; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 0.3mm solid #000; padding: 1.8mm 2mm; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-size: 8.5pt; text-transform: uppercase; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .mono { font-family: "Courier New", monospace; font-weight: bold; }
        .total td { font-weight: bold; }
        .notes { margin-top: 5mm; white-space: pre-line; }
        .notes span { display: block; font-size: 8pt; color: #444; text-transform: uppercase; }
        .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 12mm; margin-top: 12mm; text-align: center; }
        .sign .line { margin-top: 22mm; min-height: 6mm; border-top: 0.3mm solid #000; padding-top: 1.5mm; }
        .stamp { position: absolute; top: 40mm; right: 12mm; transform: rotate(-12deg); border: 0.8mm solid #b91c1c; color: #b91c1c; padding: 2mm 4mm; font-size: 13pt; font-weight: bold; letter-spacing: 1px; }
        @page { size: A4; margin: 10mm; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { margin: 0; padding: 0; max-width: none; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>
    @php
        $isDraft = in_array($po->status, ['DRAFT', 'PENDING'], true);
        $totalQty = (int) $po->items->sum('qty_ordered');
        $supplierName = $po->supplier?->name ?? $po->supplier_name;
    @endphp

    <div class="toolbar">
        <strong>PO {{ $po->po_number }}</strong>
        <button class="btn-print" onclick="window.print()">Cetak / Simpan PDF</button>
        <button class="btn-plain" type="button" id="copy-text">Salin teks</button>
        @if ($waPhone)
            <a class="btn-wa" href="https://wa.me/{{ $waPhone }}?text={{ rawurlencode($waText) }}" target="_blank" rel="noopener">Kirim ke WhatsApp supplier</a>
        @else
            <span class="hint">Nomor WhatsApp supplier belum diisi di data supplier.</span>
        @endif
        <a class="btn-plain" href="{{ route('po.show', $po->id) }}">Kembali</a>
    </div>

    <div class="sheet">
        @if ($isDraft)
            <div class="stamp">DRAFT · BELUM DISETUJUI</div>
        @endif

        <div class="head">
            <div>
                <h1>{{ $company['name'] }}</h1>
                <div class="company">
                    @if ($company['address']){{ $company['address'] }}<br>@endif
                    @if ($company['phone'])Telp. {{ $company['phone'] }}@endif
                </div>
            </div>
            <div class="doc">
                PURCHASE ORDER
                <strong>{{ $po->po_number }}</strong>
                {{ $po->created_at?->format('d/m/Y') }}
            </div>
        </div>

        <div class="meta">
            <div>
                <span>Kepada (supplier)</span>
                <strong>{{ $supplierName }}</strong>
                @if ($po->supplier)
                    <div>
                        @if ($po->supplier->contact_person){{ $po->supplier->contact_person }}<br>@endif
                        @if ($po->supplier->phone){{ $po->supplier->phone }}<br>@endif
                        {{ collect([$po->supplier->address, $po->supplier->city])->filter()->implode(', ') }}
                    </div>
                @endif
            </div>
            <div>
                <span>Target selesai</span>
                <strong>{{ $po->expected_date?->format('d/m/Y') ?? '-' }}</strong>
                <span style="margin-top: 2mm">Status</span>
                <strong>{{ $po->status_label }}</strong>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="num">No</th>
                    <th>SKU</th>
                    <th>Produk</th>
                    <th>Ukuran</th>
                    <th class="num">Qty</th>
                    <th class="num">Harga</th>
                    <th class="num">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($po->items as $item)
                    <tr>
                        <td class="num">{{ $loop->iteration }}</td>
                        <td class="mono">{{ $item->sku }}</td>
                        <td>{{ $item->masterSku?->product_name ?? '-' }}</td>
                        <td>{{ $item->masterSku?->size ?? '-' }}</td>
                        <td class="num">{{ number_format($item->qty_ordered, 0, ',', '.') }}</td>
                        <td class="num">{{ $item->price ? 'Rp ' . number_format($item->price, 0, ',', '.') : '-' }}</td>
                        <td class="num">{{ $item->price ? 'Rp ' . number_format($item->qty_ordered * $item->price, 0, ',', '.') : '-' }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="4">Total</td>
                    <td class="num">{{ number_format($totalQty, 0, ',', '.') }} pcs</td>
                    <td></td>
                    <td class="num">{{ $po->total_amount ? 'Rp ' . number_format($po->total_amount, 0, ',', '.') : '-' }}</td>
                </tr>
            </tbody>
        </table>

        @if ($po->notes)
            <div class="notes"><span>Catatan</span>{{ $po->notes }}</div>
        @endif

        <div class="sign">
            <div>
                Dibuat oleh
                <div class="line">{{ $po->approver?->name ?? $po->creator?->name }}</div>
            </div>
            <div>
                Diterima supplier
                <div class="line"></div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('copy-text').addEventListener('click', function () {
            const text = @json($waText);
            const done = () => { this.textContent = 'Tersalin'; setTimeout(() => this.textContent = 'Salin teks', 2000); };
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                const area = document.createElement('textarea');
                area.value = text;
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                area.remove();
                done();
            }
        });
    </script>
</body>
</html>
