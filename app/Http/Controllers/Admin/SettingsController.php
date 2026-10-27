<?php

namespace App\Http\Controllers\Admin; // ✅ PENTING: Namespace harus sesuai folder

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\AuditTrail;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Console\Commands\SendLowStockAlert;
use App\Services\LowStock;
use App\Services\ShipDeadline;
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
            'ship_cutoff_hour' => ShipDeadline::cutoffHour(),
            'ship_grace_days' => ShipDeadline::graceDays(),
        ];

        $whatsapp = [
            'has_token' => WhatsApp::hasToken(),
            'extra' => WhatsApp::extraTargets(),
            'members' => WhatsApp::members(),
            'ready' => WhatsApp::configured(),
            'seen_at' => WhatsApp::macroSeenAt(),
            'counts' => WhatsAppMessage::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'recent' => WhatsAppMessage::latest('id')->limit(10)->get(),
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
            'ship_cutoff_hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'ship_grace_days' => ['nullable', 'integer', 'min:0', 'max:14'],
        ]);

        $keys = array_keys($validated);
        $before = collect($keys)->mapWithKeys(fn ($k) => [$k => SystemSetting::get($k)])->all();

        SystemSetting::set('company_name', $validated['company_name'], 'COMPANY', 'Nama pengirim di label kirim');
        SystemSetting::set('company_phone', $validated['company_phone'] ?? '', 'COMPANY', 'Telepon pengirim di label kirim');
        SystemSetting::set('company_address', $validated['company_address'] ?? '', 'COMPANY', 'Alamat pengirim di label kirim');
        SystemSetting::set('low_stock_threshold', (string) $validated['low_stock_threshold'], 'INVENTORY',
            'Batas stok menipis untuk SKU yang min stoknya 0 (0 = tidak dipakai)');
        if (isset($validated['ship_cutoff_hour'])) {
            SystemSetting::set('ship_cutoff_hour', (string) $validated['ship_cutoff_hour'], 'ORDER',
                'Jam potong: order masuk sebelum jam ini harus dikirim hari itu juga');
        }
        if (isset($validated['ship_grace_days'])) {
            SystemSetting::set('ship_grace_days', (string) $validated['ship_grace_days'], 'ORDER',
                'Tambahan hari dari batas kirim order');
        }

        [$old, $new] = AuditTrail::changes($before, collect($keys)->mapWithKeys(fn ($k) => [$k => SystemSetting::get($k)])->all());
        if ($new) {
            AuditTrail::record('UPDATE', 'SETTINGS', $new, $old);
        }

        return back()->with('success', 'Pengaturan berhasil disimpan.');
    }

    /** Nomor tambahan di luar anggota. Penerima utama diatur per anggota di Kelola User. */
    public function updateWhatsApp(Request $request)
    {
        $validated = $request->validate([
            'whatsapp_target' => ['nullable', 'string', 'max:255', 'regex:' . WhatsApp::TARGET_PATTERN],
        ], [
            'whatsapp_target.regex' => 'Isi nomor WhatsApp, contoh 081234567890. Beberapa nomor dipisah koma.',
        ]);

        WhatsApp::saveExtraTargets($validated['whatsapp_target'] ?? null);
        AuditTrail::record('UPDATE_WHATSAPP', 'SETTINGS', ['nomor_tambahan' => $validated['whatsapp_target'] ?? null]);

        return back()->with('success', 'Pengaturan WhatsApp disimpan.');
    }

    /** Buat token baru untuk makro. Token hanya ditampilkan sekali. */
    public function whatsAppToken()
    {
        // Tokennya sendiri tidak dicatat
        AuditTrail::record('NEW_TOKEN', 'SETTINGS', ['token' => 'token makro WhatsApp dibuat ulang']);

        return back()->with('success', 'Token makro baru dibuat. Salin sekarang, token ini tidak ditampilkan lagi.')
            ->with('whatsapp_token', WhatsApp::generateToken());
    }

    /** Kirim pesan percobaan berisi ringkasan stok menipis saat ini. */
    public function testWhatsApp()
    {
        $message = SendLowStockAlert::message() ?? '*Tes notifikasi ' . config('app.name') . "*\nSaat ini tidak ada stok menipis.";

        if ($error = WhatsApp::send($message)) {
            return back()->withErrors(['whatsapp' => $error]);
        }

        return back()->with('success', 'Pesan tes masuk antrean. Makro akan mengirimnya saat mengambil antrean berikutnya.');
    }

    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
        ]);

        [$old, $new] = AuditTrail::changes($user->only(['name', 'email']), $validated);
        $user->update($validated);
        if ($new) {
            AuditTrail::record('UPDATE_PROFILE', 'USER', $new, $old);
        }

        return back()->with('success', 'Profil sistem berhasil diperbarui!');
    }

    public function profile()
    {
        $user = Auth::user();
        return view('settings.profile', compact('user'));
    }
}
