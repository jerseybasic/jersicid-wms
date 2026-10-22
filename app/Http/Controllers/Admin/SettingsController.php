<?php

namespace App\Http\Controllers\Admin; // ✅ PENTING: Namespace harus sesuai folder

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\SystemSetting;
use App\Models\User;
use App\Console\Commands\SendLowStockAlert;
use App\Services\LowStock;
use App\Services\Telegram;
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

        $telegram = [
            'has_token' => Telegram::token() !== null,
            'chat_id' => Telegram::chatId(),
        ];

        return view('settings.index', compact('stats', 'company', 'telegram'));
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
     * Token bot dan chat ID Telegram untuk notifikasi stok menipis.
     * Token yang dikosongkan tidak menghapus token tersimpan, kecuali "hapus" dicentang.
     */
    public function updateTelegram(Request $request)
    {
        $validated = $request->validate([
            'telegram_bot_token' => ['nullable', 'string', 'regex:' . Telegram::TOKEN_PATTERN],
            'telegram_chat_id' => ['nullable', 'string', 'regex:' . Telegram::CHAT_PATTERN],
            'telegram_clear' => ['nullable', 'boolean'],
        ], [
            'telegram_bot_token.regex' => 'Format token bot tidak dikenali. Salin token lengkap dari @BotFather, contohnya 123456789:ABC...',
            'telegram_chat_id.regex' => 'Chat ID berupa angka (boleh diawali tanda minus untuk grup) atau @namachannel.',
        ]);

        if ($request->boolean('telegram_clear')) {
            Telegram::saveToken(null);
            Telegram::saveChatId(null);

            return back()->with('success', 'Pengaturan Telegram dihapus. Notifikasi tidak dikirim lagi.');
        }

        if (!empty($validated['telegram_bot_token'])) {
            Telegram::saveToken($validated['telegram_bot_token']);
        }
        Telegram::saveChatId($validated['telegram_chat_id'] ?? null);

        return back()->with('success', 'Pengaturan Telegram disimpan.');
    }

    /** Ambil chat ID dari pesan terakhir yang diterima bot. */
    public function detectTelegramChat()
    {
        $result = Telegram::latestChat();
        if ($result['error']) {
            return back()->withErrors(['telegram' => $result['error']]);
        }

        Telegram::saveChatId($result['chat']['id']);

        return back()->with('success', "Chat ID ditemukan dan disimpan: {$result['chat']['id']} ({$result['chat']['name']}).");
    }

    /** Kirim pesan percobaan berisi ringkasan stok menipis saat ini. */
    public function testTelegram()
    {
        $message = SendLowStockAlert::message() ?? '<b>Tes notifikasi ' . e(config('app.name')) . '</b>' . "\nSaat ini tidak ada stok menipis.";

        if ($error = Telegram::send($message)) {
            return back()->withErrors(['telegram' => $error]);
        }

        return back()->with('success', 'Pesan tes terkirim ke Telegram.');
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
