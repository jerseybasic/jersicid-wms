<?php

use App\Http\Controllers\Api\WhatsAppQueueController;
use Illuminate\Support\Facades\Route;

// Antrean WhatsApp untuk makro Playwright (token dari Pengaturan → Notifikasi WhatsApp)
Route::middleware('throttle:120,1')->prefix('whatsapp')->group(function () {
    Route::get('/antrean', [WhatsAppQueueController::class, 'index'])->name('api.whatsapp.queue');
    Route::post('/antrean/{message}/terkirim', [WhatsAppQueueController::class, 'sent'])->name('api.whatsapp.sent');
    Route::post('/antrean/{message}/gagal', [WhatsAppQueueController::class, 'failed'])->name('api.whatsapp.failed');
});
