<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Facades\URL;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Services\WorkQueue;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // ✅ FIX: Beritahu Laravel lokasi manifest plugin v2
        Vite::useManifestFilename('.vite/manifest.json');
        
        URL::forceScheme('https');

        // Lonceng di header: jumlah pekerjaan yang menunggu (Tugas Hari Ini)
        View::composer('layouts.app', function ($view) {
            $user = Auth::user();
            $items = $user ? WorkQueue::for($user) : [];

            $view->with('workItems', $items);
            $view->with('workTotal', array_sum(array_column($items, 'count')));
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->email . $request->ip());
        });

        RateLimiter::for('import', function (Request $request) {
            return Limit::perMinute(3)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('orders', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
    }
}
