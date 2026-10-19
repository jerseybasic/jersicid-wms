<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Picker - {{ $pickingList->reference_type }}-{{ $pickingList->reference_id }}</title>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js" type="text/javascript"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background: #0a0e27;
            color: white;
            font-family: sans-serif;
        }

        .glass {
            background: rgba(30, 35, 60, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .neon-green {
            box-shadow: 0 0 15px rgba(34, 197, 94, 0.5);
        }

        .neon-red {
            box-shadow: 0 0 15px rgba(239, 68, 68, 0.5);
        }
    </style>
</head>

<body class="min-h-screen pb-20"
    id="picker-root"
    data-items="{{ json_encode($pickingList->items->map(fn($i) => [
          'id' => $i->id,
          'product_name' => $i->variant->product->name,
          'size' => $i->variant->size,
          'color' => $i->variant->color,
          'sku' => $i->variant->sku,
          'location_code' => $i->location_code,
          'quantity' => $i->quantity,
          'status' => $i->status
      ])) }}"
    x-data="pickerApp()">
    <!-- Header -->
    <header class="glass sticky top-0 z-40 p-4 border-b border-gray-700">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="font-bold text-lg text-cyan-400">{{ $pickingList->reference_type }}-{{ $pickingList->reference_id }}</h1>
                <p class="text-xs text-gray-400">{{ $pickingList->warehouse->name }}</p>
            </div>
            <span class="px-3 py-1 rounded-full text-xs font-bold 
                {{ $pickingList->priority === 'urgent' ? 'bg-red-500 text-white' : 'bg-yellow-500 text-black' }}">
                {{ strtoupper($pickingList->priority) }}
            </span>
        </div>
        <!-- Progress Bar -->
        <div class="mt-3 bg-gray-700 rounded-full h-2.5">
            <div class="bg-gradient-to-r from-cyan-500 to-green-500 h-2.5 rounded-full transition-all duration-500" :style="`width: ${progress}%`"></div>
        </div>
        <p class="text-xs text-gray-400 mt-1 text-center"><span x-text="pickedCount"></span> / <span x-text="totalCount"></span> items picked</p>
    </header>

    <!-- Scanner Area (Tombol Besar) -->
    <div class="p-4">
        <button @click="toggleScanner()" class="w-full py-6 rounded-2xl font-bold text-xl flex items-center justify-center transition-all
            {{ isScanning ? 'bg-red-600 neon-red' : 'bg-gradient-to-r from-green-500 to-emerald-600 neon-green' }}">
            <svg class="w-8 h-8 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path>
            </svg>
            <span x-text="isScanning ? 'STOP SCANNER' : 'SCAN QR CODE'"></span>
        </button>

        <!-- Area Kamera -->
        <div x-show="isScanning" class="mt-4 rounded-xl overflow-hidden border-2 border-cyan-500">
            <div id="qr-reader" style="width: 100%;"></div>
        </div>
    </div>

    <!-- List Items -->
    <div class="px-4 space-y-3">
        <template x-for="item in items" :key="item.id">
            <div class="glass rounded-xl p-4 border-l-4 transition-all"
                :class="item.status === 'picked' ? 'border-green-500 opacity-60' : 'border-cyan-500'">
                <div class="flex justify-between items-start">
                    <div class="flex-1">
                        <h3 class="font-bold text-white" x-text="item.product_name"></h3>
                        <p class="text-sm text-gray-400">Size: <span class="text-cyan-400 font-bold" x-text="item.size"></span> | Color: <span x-text="item.color"></span></p>
                        <p class="text-xs text-gray-500 mt-1 font-mono">SKU: <span class="font-mono">{{ $item->variant->short_sku }}</span></p>
                        <p class="text-xs text-gray-500">Lokasi: <span class="text-yellow-400 font-bold" x-text="item.location_code || '-'"></span></p>
                    </div>
                    <div class="text-right">
                        <span class="block text-2xl font-bold text-white" x-text="item.quantity"></span>
                        <span class="text-xs text-gray-400">pcs</span>
                    </div>
                </div>

                <div class="mt-3 flex justify-end">
                    <template x-if="item.status !== 'picked'">
                        <button @click="manualPick(item.id)" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-sm font-semibold">
                            Tandai Diambil
                        </button>
                    </template>
                    <template x-if="item.status === 'picked'">
                        <span class="px-4 py-2 bg-green-900/50 text-green-400 rounded-lg text-sm font-bold flex items-center">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            SELESAI
                        </span>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <!-- Toast Notification -->
    <div x-show="toast.show" x-transition class="fixed bottom-20 left-1/2 -translate-x-1/2 px-6 py-3 rounded-full font-bold shadow-lg z-50"
        :class="toast.type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'">
        <span x-text="toast.message"></span>
    </div>

    <script>
        function pickerApp() {
            var pickerRoot = document.getElementById('picker-root');
            var itemsData = JSON.parse(pickerRoot.dataset.items || '[]');

            return {
                items: itemsData,
                isScanning: false,
                html5QrcodeScanner: null,
                toast: {
                    show: false,
                    message: '',
                    type: 'success'
                },

                get pickedCount() {
                    return this.items.filter(i => i.status === 'picked').length;
                },
                get totalCount() {
                    return this.items.length;
                },
                get progress() {
                    return this.totalCount > 0 ? (this.pickedCount / this.totalCount) * 100 : 0;
                },

                toggleScanner() {
                    this.isScanning = !this.isScanning;
                    if (this.isScanning) {
                        this.$nextTick(() => {
                            this.html5QrcodeScanner = new Html5Qrcode("qr-reader");
                            const config = {
                                fps: 10,
                                qrbox: {
                                    width: 250,
                                    height: 250
                                }
                            };
                            this.html5QrcodeScanner.start({
                                facingMode: "environment"
                            }, config, this.onScanSuccess, this.onScanFailure);
                        });
                    } else {
                        if (this.html5QrcodeScanner) {
                            this.html5QrcodeScanner.stop().then(() => {
                                this.html5QrcodeScanner.clear();
                            });
                        }
                    }
                },

                onScanSuccess(decodedText) {
                    // Cari item berdasarkan SKU dari QR Code
                    let skuToFind = decodedText.trim();
                    // Jika QR berisi JSON, parse dulu
                    try {
                        const parsed = JSON.parse(decodedText);
                        if (parsed.sku) skuToFind = parsed.sku;
                    } catch (e) {}

                    const item = this.items.find(i => i.sku === skuToFind && i.status !== 'picked');

                    if (item) {
                        this.pickItem(item.id, skuToFind);
                        this.showToast(`Berhasil: ${item.product_name} (${item.size})`, 'success');
                    } else {
                        this.showToast(`SKU tidak ditemukan atau sudah diambil!`, 'error');
                    }
                },

                onScanFailure(error) {
                    /* ignore */
                },

                async pickItem(itemId, scannedSku = null) {
                    try {
                        const response = await fetch(`/picking-lists/items/${itemId}/update`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                            },
                            body: JSON.stringify({
                                status: 'picked',
                                scanned_sku: scannedSku
                            })
                        });
                        const data = await response.json();
                        if (data.success) {
                            const index = this.items.findIndex(i => i.id === itemId);
                            if (index !== -1) this.items[index].status = 'picked';
                        } else {
                            this.showToast(data.message, 'error');
                        }
                    } catch (e) {
                        this.showToast('Gagal update status', 'error');
                    }
                },

                manualPick(itemId) {
                    if (confirm('Yakin tandai item ini sudah diambil tanpa scan?')) {
                        this.pickItem(itemId);
                    }
                },

                showToast(msg, type) {
                    this.toast = {
                        show: true,
                        message: msg,
                        type: type
                    };
                    setTimeout(() => this.toast.show = false, 3000);
                }
            }
        }
    </script>
</body>

</html>