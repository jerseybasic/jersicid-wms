<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCacheHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Cache headers untuk Vite assets (JS/CSS dengan hash)
        if ($request->is('build/*') || $request->is('assets/*')) {
            $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
            $response->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
        }

        // Cache headers untuk images
        if ($request->is('images/*') || preg_match('/\.(jpg|jpeg|png|gif|webp|svg|ico)$/i', $request->path())) {
            $response->headers->set('Cache-Control', 'public, max-age=2592000, must-revalidate'); // 30 days
        }

        return $response;
    }
}