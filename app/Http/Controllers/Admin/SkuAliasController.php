<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\MasterSku;
use App\Models\SkuAlias;
use App\Models\UnregisteredSku;
use Illuminate\Http\Request;

/**
 * Pemetaan SKU marketplace ke Master SKU (Master Data → Pemetaan SKU).
 */
class SkuAliasController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q'));

        $aliases = SkuAlias::with(['masterSku:sku,product_name,size', 'creator:id,name'])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('alias', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")))
            ->orderBy('alias')
            ->paginate(50)
            ->withQueryString();

        $skuOptions = MasterSku::where('status', 'AKTIF')->orderBy('sku')->get(['sku', 'product_name', 'size']);

        $blockedCount = UnregisteredSku::pending()->count();

        return view('admin.sku-aliases.index', compact('aliases', 'search', 'skuOptions', 'blockedCount'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'alias' => ['required', 'string', 'max:100'],
            'sku' => ['required', 'string', 'max:255'],
        ], [
            'alias.required' => 'SKU marketplace wajib diisi.',
            'sku.required' => 'SKU gudang wajib dipilih.',
        ]);

        // Pilihan dari daftar berbentuk "SKU — nama produk"; ambil kodenya saja
        $sku = trim(explode(' — ', $validated['sku'])[0]);
        if ($error = SkuAlias::map($validated['alias'], $sku, auth()->id())) {
            return back()->withInput()->with('error', $error);
        }

        $alias = SkuAlias::normalize($validated['alias']);
        $this->audit($request, 'CREATE', null, ['alias' => $alias, 'sku' => SkuAlias::normalize($sku)]);

        return redirect()->route('sku-aliases.index')
            ->with('success', "{$alias} sekarang dibaca sebagai " . SkuAlias::normalize($sku) . '.');
    }

    public function destroy(Request $request, SkuAlias $skuAlias)
    {
        $skuAlias->delete();
        $this->audit($request, 'DELETE', $skuAlias->only('alias', 'sku'), null);

        return back()->with('success', "Pemetaan {$skuAlias->alias} dihapus.");
    }

    private function audit(Request $request, string $action, ?array $old, ?array $new): void
    {
        AuditTrail::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'module' => 'SKU_ALIAS',
            'old_values' => $old ? json_encode($old) : null,
            'new_values' => $new ? json_encode($new) : null,
            'ip_address' => $request->ip(),
        ]);
    }
}
