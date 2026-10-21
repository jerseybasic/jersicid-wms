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
