<?php

namespace App\Http\Controllers\Admin;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Riwayat Aktivitas (Owner/Supervisor): siapa melakukan apa, kapan, dan apa yang berubah.
 */
class AuditTrailController extends Controller
{
    private const EXPORT_LIMIT = 5000;

    public function index(Request $request)
    {
        $filters = $request->validate([
            'user' => ['nullable', 'string', 'max:64'],
            'module' => ['nullable', 'string', 'max:50'],
            'action' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = AuditTrail::with('user:id,name')
            ->when($filters['user'] ?? null, fn ($q, $user) => $user === 'system' ? $q->whereNull('user_id') : $q->where('user_id', $user))
            ->when($filters['module'] ?? null, fn ($q, $module) => $q->where('module', $module))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', \Illuminate\Support\Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', \Illuminate\Support\Carbon::parse($to)->endOfDay()))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('new_values', 'like', "%{$term}%")
                ->orWhere('old_values', 'like', "%{$term}%")))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->boolean('export')) {
            return $this->export($query);
        }

        $activities = $query->paginate(30)->withQueryString();

        $users = User::withTrashed()->orderBy('name')->get(['id', 'name']);
        $modules = AuditTrail::distinct()->orderBy('module')->pluck('module');
        $actions = AuditTrail::distinct()->orderBy('action')->pluck('action');

        return view('dashboard.audit-trails', compact('activities', 'users', 'modules', 'actions', 'filters'));
    }

    private function export($query)
    {
        $rows = $query->limit(self::EXPORT_LIMIT)->get()->map(fn (AuditTrail $a) => [
            $a->created_at->format('Y-m-d H:i:s'),
            $a->user?->name ?? 'Sistem',
            $a->module_label,
            $a->action_label,
            self::flatten($a->valuesOf('old_values')),
            self::flatten($a->valuesOf('new_values')),
            $a->ip_address,
        ])->all();

        return Excel::download(
            new ArrayExport(['Waktu', 'User', 'Modul', 'Aksi', 'Sebelum', 'Sesudah', 'IP'], $rows),
            'Riwayat_Aktivitas_' . date('Ymd_His') . '.xlsx'
        );
    }

    /** "kunci: nilai; kunci: nilai" untuk satu sel Excel */
    public static function flatten(array $values): string
    {
        return collect($values)->map(fn ($v, $k) => $k . ': ' . AuditTrail::formatValue($v))->implode('; ');
    }
}
