<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\ProductsImport;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    public function __construct(private ProductService $productService) {}

    public function index(Request $request)
    {
        $query = Product::with(['category', 'variants']);

        if ($request->filled('search')) {
            $search = $request->search;
            $keywords = explode(' ', $search);

            $query->where(function ($q) use ($keywords, $search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('sku', 'LIKE', "%{$search}%")
                    ->orWhere('brand', 'LIKE', "%{$search}%");

                foreach ($keywords as $keyword) {
                    $keyword = trim($keyword);
                    if (! empty($keyword)) {
                        $q->orWhereHas('variants', function ($vq) use ($keyword) {
                            $vq->where('sku', 'LIKE', "%{$keyword}%")
                                ->orWhere('size', 'LIKE', "%{$keyword}%");
                        });
                    }
                }
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->orderBy('name', 'desc')->paginate(15);
        $categories = Category::orderBy('name', 'asc')->get();

        return view('admin.products.index', compact('products', 'categories'));
    }

    public function create()
    {
        return view('admin.products.create', [
            'categories' => Category::orderBy('name')->get(),
            'suppliers' => Supplier::orderBy('name')->get(),
            'defaultVariants' => [['size' => 'M', 'color' => '', 'stock' => 0, 'min_stock' => 5, 'price_override' => null]],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'sku' => 'required|string|max:50|unique:products,sku',
            'category_id' => 'nullable|exists:categories,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'brand' => 'nullable|string|max:100',
            'season' => 'nullable|string|max:50',
            'year' => 'nullable|integer|min:1900|max:2100',
            'base_price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'variants' => 'required|array|min:1',
            'variants.*.size' => 'required|string|max:10',
            'variants.*.color' => 'required|string|max:50',
            'variants.*.stock' => 'required|integer|min:0',
            'variants.*.min_stock' => 'required|integer|min:0',
            'variants.*.price_override' => 'nullable|numeric|min:0',
        ]);

        $this->productService->create($validated);

        return redirect()->route('products.index')->with('success', 'Produk berhasil ditambahkan!');
    }

    public function edit(Product $product)
    {
        $product->load('variants');

        return view('admin.products.edit', [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(),
            'suppliers' => Supplier::orderBy('name')->get(),
            'variants' => $product->variants->map(fn ($v) => [
                'id' => $v->id,
                'size' => $v->size,
                'color' => $v->color,
                'stock' => $v->stock,
                'min_stock' => $v->min_stock,
                'price_override' => $v->price_override,
                'sku' => $v->sku,
            ])->values()->toArray(),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'sku' => 'required|string|max:50|unique:products,sku,'.$product->id,
            'category_id' => 'nullable|exists:categories,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'brand' => 'nullable|string|max:100',
            'season' => 'nullable|string|max:50',
            'year' => 'nullable|integer|min:1900|max:2100',
            'base_price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'variants' => 'required|array|min:1',
            'variants.*.id' => 'nullable|exists:product_variants,id',
            'variants.*.size' => 'required|string|max:10',
            'variants.*.color' => 'required|string|max:50',
            'variants.*.stock' => 'required|integer|min:0',
            'variants.*.min_stock' => 'required|integer|min:0',
            'variants.*.price_override' => 'nullable|numeric|min:0',
        ]);

        $this->productService->update($product, $validated);

        return redirect()->route('products.index')->with('success', 'Produk berhasil diupdate!');
    }

    public function destroy(Product $product)
    {
        $this->productService->delete($product);

        return redirect()->route('products.index')->with('success', 'Produk berhasil dihapus!');
    }

    public function importForm()
    {
        return view('admin.products.import');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        try {
            $import = new ProductsImport;
            Excel::import($import, $request->file('file'));

            $success = $import->successCount;
            $errors = $import->errorCount;

            if ($success > 0) {
                ActivityLogger::import(auth()->user()->name." mengimport {$success} produk via Excel", [
                    'success' => $success,
                    'errors' => $errors,
                ]);

                return redirect()->route('products.index')
                    ->with('success', "Import berhasil! {$success} produk ditambahkan.");
            }

            return back()->with('error', 'Import gagal. Tidak ada data yang tersimpan.');

        } catch (\Exception $e) {
            Log::error('Import error: '.$e->getMessage());

            return back()->with('error', 'Terjadi kesalahan: '.safe_error_message($e));
        }
    }
}
