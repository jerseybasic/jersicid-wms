<?php

namespace App\Http\Controllers\Admin; // ✅ PENTING: Namespace harus sesuai folder

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\SystemSetting;
use App\Models\User;
use App\Console\Commands\SendLowStockAlert;
use App\Services\LowStock;
use App\Services\WhatsApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index()
    {
        $stats = [
            'total_users' => User::count(),
            'active_skus' => MasterSku::where('status', 'AKTIF')->count(),
            'total_stock_value' => StockActive::join('master_skus', 'stock_actives.sku', '=', 'master_skus.sku')
                ->selectRaw('SUM(stock_actives.qty * master_skus.price) as total')
                ->value('total') ?? 0,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];

        $company = [
            'company_name' => SystemSetting::get('company_name', config('app.name')),
            'company_phone' => SystemSetting::get('company_phone', ''),
            'company_address' => SystemSetting::get('company_address', ''),
            'low_stock_threshold' => LowStock::defaultThreshold(),
        ];

        $whatsapp = [
            'has_token' => WhatsApp::token() !== null,
            'target' => WhatsApp::target(),
        ];

        return view('settings.index', compact('stats', 'company', 'whatsapp'));
    }

    /**
     * Data perusahaan (dipakai sebagai pengirim di label kirim) dan batas stok menipis umum.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:100'],
            'company_phone' => ['nullable', 'string', 'max:30'],
            'company_address' => ['nullable', 'string', 'max:255'],
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        SystemSetting::set('company_name', $validated['company_name'], 'COMPANY', 'Nama pengirim di label kirim');
        SystemSetting::set('company_phone', $validated['company_phone'] ?? '', 'COMPANY', 'Telepon pengirim di label kirim');
        SystemSetting::set('company_address', $validated['company_address'] ?? '', 'COMPANY', 'Alamat pengirim di label kirim');
        SystemSetting::set('low_stock_threshold', (string) $validated['low_stock_threshold'], 'INVENTORY',
            'Batas stok menipis untuk SKU yang min stoknya 0 (0 = tidak dipakai)');

        return back()->with('success', 'Pengaturan berhasil disimpan.');
    }

    /**
     * Token Fonnte dan nomor WhatsApp tujuan untuk notifikasi stok menipis.
     * Token yang dikosongkan tidak menghapus token tersimpan, kecuali "hapus" dicentang.
     */
    public function updateWhatsApp(Request $request)
    {
        $validated = $request->validate([
            'whatsapp_token' => ['nullable', 'string', 'regex:' . WhatsApp::TOKEN_PATTERN],
            'whatsapp_target' => ['nullable', 'string', 'max:255', 'regex:' . WhatsApp::TARGET_PATTERN],
            'whatsapp_clear' => ['nullable', 'boolean'],
        ], [
            'whatsapp_token.regex' => 'Format token tidak dikenali. Salin token perangkat dari dashboard Fonnte.',
            'whatsapp_target.regex' => 'Isi nomor WhatsApp (contoh 081234567890) atau ID grup Fonnte. Beberapa tujuan dipisah koma.',
        ]);

        if ($request->boolean('whatsapp_clear')) {
            WhatsApp::saveToken(null);
            WhatsApp::saveTarget(null);

            return back()->with('success', 'Pengaturan WhatsApp dihapus. Notifikasi tidak dikirim lagi.');
        }

        if (!empty($validated['whatsapp_token'])) {
            WhatsApp::saveToken($validated['whatsapp_token']);
        }
        WhatsApp::saveTarget($validated['whatsapp_target'] ?? null);

        return back()->with('success', 'Pengaturan WhatsApp disimpan.');
    }

    /** Kirim pesan percobaan berisi ringkasan stok menipis saat ini. */
    public function testWhatsApp()
    {
        $message = SendLowStockAlert::message() ?? '*Tes notifikasi ' . config('app.name') . "*\nSaat ini tidak ada stok menipis.";

        if ($error = WhatsApp::send($message)) {
            return back()->withErrors(['whatsapp' => $error]);
        }

        return back()->with('success', 'Pesan tes terkirim ke WhatsApp.');
    }

    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
        ]);

        $user->update($validated);

        return back()->with('success', 'Profil sistem berhasil diperbarui!');
    }

    public function profile()
    {
        $user = Auth::user();
        return view('settings.profile', compact('user'));
    }
}
