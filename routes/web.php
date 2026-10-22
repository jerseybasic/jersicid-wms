<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\MasterSkuController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\InsightReportController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Warehouse\PurchaseOrderController;
use App\Http\Controllers\Warehouse\InboundController;
use App\Http\Controllers\Warehouse\OrderImportController;
use App\Http\Controllers\Warehouse\OutboundController;
use App\Http\Controllers\Warehouse\PickingController;
use App\Http\Controllers\Warehouse\PackingController;
use App\Http\Controllers\Warehouse\TransferController;
use App\Http\Controllers\Warehouse\StockOpnameController;
use App\Http\Controllers\Warehouse\WarehouseMappingController;
use App\Http\Controllers\Warehouse\BarcodeScannerController;
use App\Http\Controllers\Warehouse\PrinterController;
use App\Http\Controllers\Warehouse\LabelController;
use App\Http\Controllers\Warehouse\TaskController;
use App\Http\Controllers\Warehouse\ReturnController;
use App\Http\Controllers\Auth\LoginController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// ==================== AUTH ====================
Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');

Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// ==================== DASHBOARD ====================
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard/owner', [DashboardController::class, 'owner'])->name('dashboard.owner');
    Route::get('/dashboard/gudang', [DashboardController::class, 'gudang'])->name('dashboard.gudang');
});

// ==================== MASTER SKU (OWNER & SUPERVISOR) ====================
Route::middleware(['auth', 'role:OWNER,SUPERVISOR'])
    ->prefix('master-skus')
    ->name('master-skus.')
    ->group(function () {
        Route::get('/', [MasterSkuController::class, 'index'])->name('index');
        Route::get('/create', [MasterSkuController::class, 'create'])->name('create');
        Route::post('/', [MasterSkuController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [MasterSkuController::class, 'edit'])->name('edit');
        Route::put('/{id}', [MasterSkuController::class, 'update'])->name('update');
        Route::delete('/{id}', [MasterSkuController::class, 'destroy'])->name('destroy');
        Route::get('/article/{productName}/{version}/skus', [MasterSkuController::class, 'getArticleSkus'])->name('master-skus.article-skus');
        Route::get('/export', [MasterSkuController::class, 'export'])->name('export');
        Route::post('/import', [MasterSkuController::class, 'import'])->name('import');
        // Reset menghapus seluruh Master SKU + stok, jadi hanya OWNER
    });

// ==================== SUPPLIER (OWNER & SUPERVISOR) ====================
Route::middleware(['auth', 'role:OWNER,SUPERVISOR'])->prefix('suppliers')->group(function () {
    Route::get('/', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('/create', [SupplierController::class, 'create'])->name('suppliers.create');
    Route::post('/', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::get('/{id}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
    Route::put('/{id}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('/{id}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
    Route::patch('/{id}/toggle-status', [SupplierController::class, 'toggleStatus'])->name('suppliers.toggle-status');
});

// ==================== PURCHASE ORDER ====================
// Buat/edit/approve: OWNER & SUPERVISOR (dicek di controller). ADMIN_GUDANG: lihat & update tahap produksi.
Route::middleware(['auth', 'role:OWNER,SUPERVISOR,ADMIN_GUDANG'])
    ->prefix('purchase-orders')
    ->name('po.')
    ->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->name('index');
        Route::get('/create', [PurchaseOrderController::class, 'create'])->name('create');
        Route::post('/', [PurchaseOrderController::class, 'store'])->name('store');
        Route::get('/{id}', [PurchaseOrderController::class, 'show'])->name('show');
        Route::get('/{id}/edit', [PurchaseOrderController::class, 'edit'])->name('edit');
        Route::put('/{id}', [PurchaseOrderController::class, 'update'])->name('update');
        Route::delete('/{id}', [PurchaseOrderController::class, 'destroy'])->name('destroy');
        Route::patch('/{id}/status', [PurchaseOrderController::class, 'updateStatus'])->name('update-status');
    });

// ==================== INBOUND (GUDANG) ====================
Route::middleware(['auth', 'role:OWNER,SUPERVISOR,ADMIN_GUDANG,STAFF_GUDANG'])->prefix('gudang/inbound')->group(function () {
    Route::get('/', [InboundController::class, 'index'])->name('gudang.inbound');
    Route::get('/create', [InboundController::class, 'create'])->name('gudang.inbound.create');
    Route::post('/', [InboundController::class, 'store'])->name('gudang.inbound.store');
    Route::get('/{id}', [InboundController::class, 'show'])->name('gudang.inbound.show');
});

// ==================== OUTBOUND (GUDANG) ====================
Route::middleware(['auth', 'role:OWNER,SUPERVISOR,ADMIN_GUDANG,STAFF_GUDANG'])->prefix('gudang/outbound')->name('gudang.')->group(function () {
    Route::get('/', [OutboundController::class, 'index'])->name('outbound');
    Route::get('/create', [OutboundController::class, 'create'])->name('outbound.create');
    Route::get('/import', [OrderImportController::class, 'create'])->name('outbound.import');
    Route::post('/import/preview', [OrderImportController::class, 'preview'])->name('outbound.import.preview');
    Route::post('/import', [OrderImportController::class, 'store'])->name('outbound.import.store');
    Route::post('/', [OutboundController::class, 'store'])->name('outbound.store');
    Route::patch('/{id}/status', [OutboundController::class, 'updateStatus'])->name('outbound.update-status');
});

// ==================== RETUR (GUDANG) ====================
Route::middleware(['auth', 'role:OWNER,SUPERVISOR,ADMIN_GUDANG,STAFF_GUDANG'])->prefix('gudang/retur')->name('gudang.')->group(function () {
    Route::get('/', [ReturnController::class, 'index'])->name('retur');
    Route::get('/create', [ReturnController::class, 'create'])->name('retur.create');
    Route::post('/', [ReturnController::class, 'store'])->name('retur.store');
    Route::get('/{id}', [ReturnController::class, 'show'])->name('retur.show');
});

// ==================== PICKING (GUDANG) ====================
Route::middleware(['auth', 'role:OWNER,SUPERVISOR,ADMIN_GUDANG,STAFF_GUDANG'])
    ->prefix('gudang/picking')
    ->name('gudang.')
    ->group(function () {
        Route::get('/', [PickingController::class, 'index'])->name('picking');
        Route::get('/create', [PickingController::class, 'create'])->name('picking.create');
        Route::post('/', [PickingController::class, 'store'])->name('picking.store');
        Route::get('/{id}', [PickingController::class, 'show'])->name('picking.show');
        Route::post('/{id}/start', [PickingController::class, 'start'])->name('picking.start');
        Route::post('/{sessionId}/items/{itemId}/pick', [PickingController::class, 'pickItem'])->name('picking.pick');
        Route::post('/{id}/scan', [PickingController::class, 'scan'])->name('picking.scan');
        Route::delete('/{id}', [PickingController::class, 'destroy'])->name('picking.destroy');
    });

// ==================== PACKING (GUDANG) ====================
Route::middleware(['auth', 'role:OWNER,SUPERVISOR,ADMIN_GUDANG,STAFF_GUDANG'])
    ->prefix('gudang/packing')
    ->name('gudang.')
    ->group(function () {
        Route::get('/', [PackingController::class, 'index'])->name('packing');
        Route::get('/create', [PackingController::class, 'create'])->name('packing.create');
        Route::post('/', [PackingController::class, 'store'])->name('packing.store');
        Route::get('/{id}', [PackingController::class, 'show'])->name('packing.show');
        Route::post('/{id}/start', [PackingController::class, 'start'])->name('packing.start');
        Route::post('/{sessionId}/items/{itemId}/pack', [PackingController::class, 'packItem'])->name('packing.pack');
        Route::post('/{id}/scan', [PackingController::class, 'scan'])->name('packing.scan');
        Route::post('/{id}/complete', [PackingController::class, 'complete'])->name('packing.complete');
        Route::delete('/{id}', [PackingController::class, 'destroy'])->name('packing.destroy');
    });

// ==================== TRANSFER STOK (GUDANG) ====================
Route::middleware(['auth', 'role:OWNER,SUPERVISOR,ADMIN_GUDANG,STAFF_GUDANG'])->prefix('gudang/transfer')->group(function () {
    Route::get('/', [TransferController::class, 'index'])->name('gudang.transfer');
    Route::post('/', [TransferController::class, 'store'])->name('gudang.transfer.store');
});

// ==================== STOCK OPNAME (GUDANG) ====================
Route::middleware(['auth', 'role:OWNER,SUPERVISOR,ADMIN_GUDANG,STAFF_GUDANG'])->prefix('gudang/opname')->group(function () {
    Route::get('/', [StockOpnameController::class, 'index'])->name('gudang.opname');
    Route::get('/create', [StockOpnameController::class, 'create'])->name('gudang.opname.create');
    Route::post('/', [StockOpnameController::class, 'store'])->name('gudang.opname.store');
    Route::get('/{id}', [StockOpnameController::class, 'show'])->name('gudang.opname.show');
    Route::post('/{id}/start', [StockOpnameController::class, 'start'])->name('gudang.opname.start');
    Route::post('/{id}/update', [StockOpnameController::class, 'updatePhysicalQty'])->name('gudang.opname.update');
    Route::post('/{id}/item', [StockOpnameController::class, 'addItem'])->name('gudang.opname.add-item');
    Route::post('/{id}/complete', [StockOpnameController::class, 'complete'])->name('gudang.opname.complete');
    Route::delete('/{id}', [StockOpnameController::class, 'destroy'])->name('gudang.opname.destroy');
});

// ==================== WAREHOUSE MAPPING (GUDANG) ====================
Route::middleware(['auth', 'role:OWNER,SUPERVISOR,ADMIN_GUDANG,STAFF_GUDANG'])
    ->prefix('gudang/mapping')
    ->group(function () {
        Route::get('/', [WarehouseMappingController::class, 'index'])->name('gudang.mapping');
        Route::get('/{rackCode}', [WarehouseMappingController::class, 'show'])->name('gudang.mapping.show');
        Route::post('/{rackCode}/assign', [WarehouseMappingController::class, 'assign'])->name('gudang.mapping.assign');
        Route::delete('/{rackCode}/{sku}', [WarehouseMappingController::class, 'unassign'])->name('gudang.mapping.unassign');
    });

// Cek barang & lokasi (hanya baca, tidak mengubah stok)
Route::middleware(['auth', 'role:OWNER,SUPERVISOR,ADMIN_GUDANG,STAFF_GUDANG'])
    ->prefix('gudang/scanner')
    ->name('gudang.')
    ->group(function () {
        Route::get('/', [BarcodeScannerController::class, 'index'])->name('scanner');
        Route::get('/lookup', [BarcodeScannerController::class, 'lookup'])->name('scanner.lookup');
    });

// Thermal Printer
Route::middleware(['auth', 'role:OWNER,SUPERVISOR,ADMIN_GUDANG,STAFF_GUDANG'])
    ->prefix('gudang/printer')
    ->name('gudang.')
    ->group(function () {
        Route::get('/', [PrinterController::class, 'index'])->name('printer');
        Route::post('/preview-label', [PrinterController::class, 'previewLabel'])->name('printer.preview-label');
        Route::post('/download-pdf', [PrinterController::class, 'downloadPdf'])->name('printer.download-pdf');
        Route::post('/print-sku', [PrinterController::class, 'printSkuLabel'])->name('printer.print-sku');
        Route::post('/preview-zpl', [PrinterController::class, 'previewZpl'])->name('printer.preview');
        Route::post('/print-shipping', [PrinterController::class, 'printShippingLabel'])->name('printer.print-shipping');
        Route::post('/save-template', [PrinterController::class, 'saveTemplate'])->name('printer.save-template');
        Route::put('/template/{id}', [PrinterController::class, 'updateTemplate'])->name('printer.update-template');
        Route::delete('/template/{id}', [PrinterController::class, 'deleteTemplate'])->name('printer.delete-template');
        Route::post('/test-connection', [PrinterController::class, 'testConnection'])->name('printer.test');
    });

// Tugas staf (tampilan HP) dan cetak label dari browser
Route::middleware(['auth', 'role:OWNER,SUPERVISOR,ADMIN_GUDANG,STAFF_GUDANG'])
    ->prefix('gudang')
    ->name('gudang.')
    ->group(function () {
        Route::get('/tugas', [TaskController::class, 'index'])->name('tugas');
        Route::get('/label/sku', [LabelController::class, 'sku'])->name('label.sku');
        Route::get('/label/kirim-massal', [LabelController::class, 'shippingBulk'])->name('label.shipping-bulk');
        Route::get('/label/kirim/{order}', [LabelController::class, 'shipping'])->name('label.shipping');
    });

// ==================== REPORTS (OWNER & SUPERVISOR) ====================
Route::middleware(['auth', 'role:OWNER,SUPERVISOR'])->prefix('reports')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/stock', [ReportController::class, 'stock'])->name('reports.stock');
    Route::get('/movement', [ReportController::class, 'movement'])->name('reports.movement');
    Route::get('/opname', [ReportController::class, 'opname'])->name('reports.opname');
    Route::get('/kinerja', [InsightReportController::class, 'performance'])->name('reports.performance');
    Route::get('/barang-lambat', [InsightReportController::class, 'slowMoving'])->name('reports.slow-moving');
    Route::get('/akurasi-opname', [InsightReportController::class, 'opnameAccuracy'])->name('reports.opname-accuracy');
    Route::get('/nilai-stok', [InsightReportController::class, 'stockValue'])->name('reports.stock-value');
    Route::get('/import-sku', function () {
        return view('reports.import-sku');
    })->name('reports.import-sku-form');
    Route::post('/import-sku', [ReportController::class, 'importSku'])->name('reports.import-sku');
    Route::get('/download-template', [ReportController::class, 'downloadTemplate'])->name('reports.download-template');
});

// ==================== PROFIL (SEMUA USER: ubah profil sendiri) ====================
Route::middleware(['auth'])->prefix('settings')->name('settings.')->group(function () {
    Route::get('/profile', [SettingsController::class, 'profile'])->name('update-profile');
    Route::put('/profile', [SettingsController::class, 'updateProfile'])->name('profile.update');
});

// ==================== SETTINGS (OWNER ONLY) ====================
Route::middleware(['auth', 'role:OWNER'])->prefix('settings')->name('settings.')->group(function () {
    Route::get('/', [SettingsController::class, 'index'])->name('index');
    Route::put('/', [SettingsController::class, 'update'])->name('update');
    Route::put('/telegram', [SettingsController::class, 'updateTelegram'])->name('telegram');
    Route::post('/telegram/cari-chat', [SettingsController::class, 'detectTelegramChat'])->name('telegram.detect');
    Route::post('/telegram/tes', [SettingsController::class, 'testTelegram'])->name('telegram.test');
    Route::post('/clear-cache', function () {
        \Illuminate\Support\Facades\Artisan::call('cache:clear');
        \Illuminate\Support\Facades\Artisan::call('view:clear');
        \Illuminate\Support\Facades\Artisan::call('config:clear');
        return back()->with('success', 'Cache berhasil dibersihkan!');
    })->name('clear-cache');
});

// Fix: Tambah route toggle-status untuk users
Route::middleware(['auth', 'role:OWNER'])->prefix('users')->group(function () {
    Route::get('/', [UserController::class, 'index'])->name('users.index');
    Route::get('/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/', [UserController::class, 'store'])->name('users.store');
    Route::get('/{id}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/{id}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/{id}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::patch('/{id}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');
});

// ==================== AUDIT TRAIL (OWNER & SUPERVISOR) ====================
Route::middleware(['auth', 'role:OWNER,SUPERVISOR'])->get('/audit-trails', function () {
    $activities = \App\Models\AuditTrail::with('user:id,name')
        ->orderBy('created_at', 'desc')
        ->paginate(20);
    return view('dashboard.audit-trails', compact('activities'));
})->name('audit-trails.index');
