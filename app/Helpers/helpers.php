<?php

use App\Models\Setting;

if (!function_exists('format_rupiah')) {
    function format_rupiah($amount)
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}

if (!function_exists('setting')) {
    function setting($key, $default = null)
    {
        return Setting::get($key, $default);
    }
}

if (!function_exists('generate_invoice_number')) {
    function generate_invoice_number()
    {
        return 'INV-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(6));
    }
}

if (!function_exists('generate_po_number')) {
    function generate_po_number()
    {
        return 'PO-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(6));
    }
}

if (!function_exists('generate_return_number')) {
    function generate_return_number()
    {
        return 'RET-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(6));
    }
}

if (!function_exists('log_activity')) {
    function log_activity($action, $module, $description = null)
    {
        if (auth()->check()) {
            \App\Models\UserActivityLog::log(
                auth()->id(),
                $action,
                $module,
                $description
            );
        }
    }
}

if (!function_exists('product_image')) {
    function product_image($imagePath, $size = 'medium')
    {
        if (!$imagePath) {
            return asset('images/placeholder-product.png');
        }

        $fullPath = storage_path('app/public/' . $imagePath);

        if (!file_exists($fullPath)) {
            return asset('images/placeholder-product.png');
        }

        return asset('storage/' . $imagePath);
    }
}

if (!function_exists('safe_error_message')) {
    /**
     * Pesan error yang aman ditampilkan ke user. Pesan bisnis (mis. "Stok tidak cukup")
     * diteruskan apa adanya, sedangkan error database/PHP disembunyikan karena bisa
     * membocorkan struktur tabel, query, atau path server.
     */
    function safe_error_message(\Throwable $e): string
    {
        $isInternal = $e instanceof \Illuminate\Database\QueryException
            || $e instanceof \PDOException
            || $e instanceof \ErrorException
            || $e instanceof \Error
            || str_contains($e->getMessage(), 'SQLSTATE');

        if ($isInternal) {
            report($e);

            return 'Terjadi kesalahan sistem. Silakan coba lagi atau hubungi admin.';
        }

        return $e->getMessage();
    }
}
