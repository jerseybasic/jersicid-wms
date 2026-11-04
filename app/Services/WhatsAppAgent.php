<?php

namespace App\Services;

use App\Models\SystemSetting;
use Carbon\Carbon;

/**
 * Agen WhatsApp ringan (folder whatsapp-agent/) yang dikendalikan dari WMS.
 *
 * Agen melapor statusnya tiap ~20 detik: menunggu scan (beserta QR), tersambung (nomor dan daftar grup),
 * atau terputus. Owner melihat QR dan statusnya di Pengaturan, dan bisa memberi perintah
 * (putuskan nomor / sambung ulang / muat ulang grup) yang diambil agen pada laporan berikutnya.
 */
class WhatsAppAgent
{
    public const STATES = ['connecting', 'qr', 'connected', 'logged_out'];
    public const COMMANDS = ['logout', 'restart', 'refresh_groups'];

    /** Agen dianggap tidak berjalan jika tidak melapor selama ini */
    public const OFFLINE_AFTER_SECONDS = 70;

    private const STATUS_KEY = 'whatsapp_agent_status';
    private const COMMAND_KEY = 'whatsapp_agent_command';

    /**
     * Simpan laporan agen dan kembalikan perintah yang menunggu (sekali ambil).
     */
    public static function report(array $data): ?string
    {
        $groups = collect($data['groups'] ?? [])
            ->filter(fn ($g) => is_array($g) && filled($g['id'] ?? null))
            ->map(fn ($g) => ['id' => (string) $g['id'], 'name' => mb_substr((string) ($g['name'] ?? $g['id']), 0, 100)])
            ->take(300)->values()->all();

        $status = [
            'state' => in_array($data['state'] ?? null, self::STATES, true) ? $data['state'] : 'connecting',
            'qr' => ($data['state'] ?? null) === 'qr' ? mb_substr((string) ($data['qr'] ?? ''), 0, 1000) : null,
            'phone' => isset($data['phone']) ? preg_replace('/\D/', '', (string) $data['phone']) : null,
            'name' => isset($data['name']) ? mb_substr((string) $data['name'], 0, 100) : null,
            'groups' => $groups,
            'version' => mb_substr((string) ($data['version'] ?? ''), 0, 20),
            'memory_mb' => isset($data['memory_mb']) ? (int) $data['memory_mb'] : null,
            'seen_at' => now()->toDateTimeString(),
        ];
        SystemSetting::set(self::STATUS_KEY, json_encode($status), 'NOTIFICATION', 'Status agen WhatsApp');
        // Pengambilan antrean dan laporan agen sama-sama tanda agen hidup
        SystemSetting::set('whatsapp_macro_seen_at', $status['seen_at'], 'NOTIFICATION', 'Terakhir makro WhatsApp mengambil antrean');

        $command = SystemSetting::get(self::COMMAND_KEY) ?: null;
        if ($command) {
            SystemSetting::set(self::COMMAND_KEY, '', 'NOTIFICATION', 'Perintah untuk agen WhatsApp');
        }

        return $command;
    }

    /**
     * Status terakhir agen. online = melapor dalam 70 detik terakhir.
     *
     * @return array{state: string, qr: ?string, phone: ?string, name: ?string, groups: array, version: ?string, memory_mb: ?int, seen_at: ?Carbon, online: bool, pending: ?string}
     */
    public static function status(): array
    {
        $raw = json_decode((string) SystemSetting::get(self::STATUS_KEY, ''), true) ?: [];
        $seen = !empty($raw['seen_at']) ? Carbon::parse($raw['seen_at']) : null;
        $online = $seen !== null && $seen->gt(now()->subSeconds(self::OFFLINE_AFTER_SECONDS));

        return [
            'state' => $online ? ($raw['state'] ?? 'connecting') : 'offline',
            'qr' => $online ? ($raw['qr'] ?? null) : null,
            'phone' => $raw['phone'] ?? null,
            'name' => $raw['name'] ?? null,
            'groups' => $raw['groups'] ?? [],
            'version' => $raw['version'] ?? null,
            'memory_mb' => $raw['memory_mb'] ?? null,
            'seen_at' => $seen,
            'online' => $online,
            'pending' => SystemSetting::get(self::COMMAND_KEY) ?: null,
        ];
    }

    /** Nama grup yang diikuti nomor pengirim, untuk dipilih sebagai penerima */
    public static function groupNames(): array
    {
        return collect(self::status()['groups'])->pluck('name')->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    public static function command(string $command): void
    {
        abort_unless(in_array($command, self::COMMANDS, true), 422);
        SystemSetting::set(self::COMMAND_KEY, $command, 'NOTIFICATION', 'Perintah untuk agen WhatsApp');
    }
}
