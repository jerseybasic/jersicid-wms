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

        // ✅ FIX: Share notifications data ke semua view yang menggunakan app layout
        View::composer('layouts.app', function ($view) {
            $user = Auth::user();

            if ($user) {
                // Ambil notifikasi yang belum dibaca
                $unreadNotifications = $user->unreadNotifications()->count();

                // Ambil 5 notifikasi terbaru
                $notifications = $user->notifications()->take(5)->get();
            } else {
                $unreadNotifications = 0;
                $notifications = collect();
            }

            $view->with('unreadNotifications', $unreadNotifications);
            $view->with('notifications', $notifications);
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
