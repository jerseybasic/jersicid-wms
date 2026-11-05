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
use App\Services\DailyBackup;
use App\Services\DailyShipmentReport;
use App\Services\WhatsAppSchedule;
use App\Services\LowStock;
use App\Services\ShipDeadline;
use App\Services\PoNotifier;
use App\Services\ProfilePhoto;
use App\Services\WhatsApp;
use App\Services\WhatsAppAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
            'po_notify' => PoNotifier::enabled(),
            'seen_at' => WhatsApp::macroSeenAt(),
            'counts' => WhatsAppMessage::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'recent' => WhatsAppMessage::latest('id')->limit(10)->get(),
            'agent' => self::agentView(WhatsAppAgent::status()),
        ];

        $report = [
            'enabled' => DailyShipmentReport::enabled(),
            'time' => DailyShipmentReport::time(),
            'targets' => DailyShipmentReport::rawTargets(),
            'parsed' => DailyShipmentReport::targets(),
            'log' => DailyShipmentReport::log(),
            'preview' => DailyShipmentReport::message(),
            'groups' => WhatsAppAgent::groupNames(),
            'schedule' => WhatsAppSchedule::overview(),
        ];

        $backup = DailyBackup::overview();

        return view('settings.index', compact('stats', 'company', 'whatsapp', 'report', 'backup'));
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
        PoNotifier::setEnabled($request->boolean('po_auto_notify'));
        AuditTrail::record('UPDATE_WHATSAPP', 'SETTINGS', [
            'nomor_tambahan' => $validated['whatsapp_target'] ?? null,
            'notifikasi_po' => PoNotifier::enabled() ? 'Aktif' : 'Mati',
        ]);

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

    /** Status koneksi agen WhatsApp untuk kartu "Koneksi WhatsApp" (diperbarui tiap beberapa detik) */
    public function whatsAppAgentStatus()
    {
        return response()->json(self::agentView(WhatsAppAgent::status()));
    }

    /** Perintah owner ke agen: putuskan nomor (scan ulang), sambung ulang, atau muat ulang daftar grup */
    public function whatsAppAgentCommand(Request $request)
    {
        $validated = $request->validate(['command' => ['required', 'in:' . implode(',', WhatsAppAgent::COMMANDS)]]);
        WhatsAppAgent::command($validated['command']);
        AuditTrail::record('WHATSAPP_AGENT', 'SETTINGS', ['perintah' => $validated['command']]);

        $message = [
            'logout' => 'Nomor akan diputuskan. QR baru muncul di sini dalam beberapa detik untuk scan nomor pengirim.',
            'restart' => 'Agen akan menyambung ulang dalam beberapa detik.',
            'refresh_groups' => 'Daftar grup akan diperbarui dalam beberapa detik.',
        ][$validated['command']];

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => $message])
            : back()->with('success', $message);
    }

    /** Data agen untuk tampilan: label status, QR sebagai SVG, waktu terakhir terlihat */
    public static function agentView(array $agent): array
    {
        $labels = [
            'connected' => 'Tersambung',
            'qr' => 'Menunggu scan QR',
            'connecting' => 'Menyambung…',
            'logged_out' => 'Nomor diputuskan',
            'offline' => 'Agen tidak berjalan',
        ];

        return [
            'state' => $agent['state'],
            'label' => $labels[$agent['state']] ?? $agent['state'],
            'qr_svg' => $agent['qr'] ? (string) \SimpleSoftwareIO\QrCode\Facades\QrCode::size(240)->margin(1)->generate($agent['qr']) : null,
            'phone' => $agent['phone'] ? '+' . $agent['phone'] : null,
            'name' => $agent['name'],
            'groups' => count($agent['groups']),
            'memory_mb' => $agent['memory_mb'],
            'version' => $agent['version'],
            'seen' => $agent['seen_at'] ? $agent['seen_at']->diffForHumans() . ' (' . $agent['seen_at']->format('d/m H:i') . ')' : 'belum pernah',
            'pending' => $agent['pending'],
        ];
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

    /** Laporan kiriman harian: aktif/mati, jam kirim, dan penerima (nomor pribadi atau nama grup WhatsApp) */
    public function updateDailyReport(Request $request)
    {
        $validated = $request->validate([
            'daily_report_time' => ['required', 'date_format:H:i'],
            'daily_report_targets' => ['nullable', 'string', 'max:2000'],
        ], [
            'daily_report_time.date_format' => 'Jam kirim harus berformat JJ:MM, contoh 17:00.',
        ]);

        $targets = DailyShipmentReport::targets($validated['daily_report_targets'] ?? '');
        if (count($targets) > DailyShipmentReport::MAX_TARGETS) {
            return back()->withInput()->withErrors(['daily_report_targets' => 'Maksimal ' . DailyShipmentReport::MAX_TARGETS . ' penerima.']);
        }
        foreach ($targets as $target) {
            if (mb_strlen($target['to']) > 100) {
                return back()->withInput()->withErrors(['daily_report_targets' => 'Nama grup maksimal 100 huruf: ' . \Illuminate\Support\Str::limit($target['to'], 40)]);
            }
        }
        $enabled = $request->boolean('daily_report_enabled');
        if ($enabled && $targets === []) {
            return back()->withInput()->withErrors(['daily_report_targets' => 'Isi minimal satu nomor atau nama grup WhatsApp untuk mengaktifkan laporan.']);
        }

        $before = ['aktif' => DailyShipmentReport::enabled() ? 'Ya' : 'Tidak', 'jam' => DailyShipmentReport::time(), 'penerima' => DailyShipmentReport::rawTargets()];
        DailyShipmentReport::save($enabled, $validated['daily_report_time'], $validated['daily_report_targets'] ?? '');
        $after = ['aktif' => DailyShipmentReport::enabled() ? 'Ya' : 'Tidak', 'jam' => DailyShipmentReport::time(), 'penerima' => DailyShipmentReport::rawTargets()];

        [$old, $new] = AuditTrail::changes($before, $after);
        if ($new) {
            AuditTrail::record('UPDATE_DAILY_REPORT', 'SETTINGS', $new, $old);
        }

        return back()->with('success', 'Pengaturan laporan kiriman harian disimpan.');
    }

    /** Kirim uji: laporan hari ini langsung masuk antrean, tanpa mengubah jadwal harian */
    public function testDailyReport()
    {
        if ($error = DailyShipmentReport::queue(markSent: false)) {
            return back()->withErrors(['daily_report' => $error]);
        }
        AuditTrail::record('TEST_DAILY_REPORT', 'SETTINGS', ['penerima' => count(DailyShipmentReport::targets())]);

        return back()->with('success', 'Laporan kiriman hari ini masuk antrean. Makro akan mengirimnya saat mengambil antrean berikutnya.');
    }

    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'photo.image' => 'Foto profil harus berupa gambar.',
            'photo.mimes' => 'Foto profil harus JPG, PNG, atau WEBP.',
            'photo.max' => 'Foto profil maksimal 5 MB.',
        ]);

        $fields = ['name' => $validated['name'], 'email' => $validated['email']];
        [$old, $new] = AuditTrail::changes($user->only(['name', 'email']), $fields);
        $user->update($fields);

        $hadPhoto = (bool) $user->profile_photo_path;
        try {
            if ($request->hasFile('photo')) {
                ProfilePhoto::store($user, $request->file('photo'));
                $old['foto_profil'] = $hadPhoto ? 'Ada' : 'Belum ada';
                $new['foto_profil'] = $hadPhoto ? 'Diganti' : 'Ditambahkan';
            } elseif ($request->boolean('remove_photo') && $hadPhoto) {
                ProfilePhoto::remove($user);
                $old['foto_profil'] = 'Ada';
                $new['foto_profil'] = 'Dihapus';
            }
        } catch (\RuntimeException $e) {
            return back()->withErrors(['photo' => $e->getMessage()]);
        }

        if ($new) {
            AuditTrail::record('UPDATE_PROFILE', 'USER', $new, $old);
        }

        return back()->with('success', 'Profil sistem berhasil diperbarui!');
    }

    public function profile()
    {
        $user = Auth::user();
        // Hanya Owner yang punya halaman Settings, jadi tombol kembali selalu ke dashboard
        $backUrl = route($user->role === 'OWNER' ? 'dashboard.owner' : 'dashboard.gudang');
        return view('settings.profile', compact('user', 'backUrl'));
    }

    /** Tampilkan foto profil seorang anggota (semua yang login boleh melihat) */
    public function photo(User $user)
    {
        abort_unless($user->profile_photo_path && Storage::disk('local')->exists($user->profile_photo_path), 404);

        return response()->file(Storage::disk('local')->path($user->profile_photo_path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }
}
