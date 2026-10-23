<?php

namespace App\Http\Controllers;

use App\Services\QuickSearch;
use Illuminate\Http\Request;

/**
 * Kotak cari di atas setiap halaman. Permintaan JSON mengisi daftar saran saat mengetik;
 * Enter membuka halaman hasil, atau langsung ke tujuannya jika kodenya persis cocok dengan satu data.
 */
class SearchController extends Controller
{
    public function index(Request $request)
    {
        $query = trim((string) $request->query('q', ''));
        $groups = QuickSearch::run($query, $request->user(), $request->wantsJson() ? 5 : 20);

        if ($request->wantsJson()) {
            return response()->json(['query' => $query, 'groups' => $groups, 'exact' => QuickSearch::exactUrl($groups)]);
        }

        if ($url = QuickSearch::exactUrl($groups)) {
            return redirect($url);
        }

        return view('search.index', ['query' => $query, 'groups' => $groups, 'tooShort' => mb_strlen($query) < QuickSearch::MIN_LENGTH]);
    }
}
