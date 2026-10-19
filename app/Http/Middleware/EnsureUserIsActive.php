<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Logout user yang dinonaktifkan OWNER walaupun sesinya masih berjalan.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status !== 'AKTIF') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Akun Anda tidak aktif.'], 401);
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Akun Anda tidak aktif. Hubungi OWNER.',
            ]);
        }

        return $next($request);
    }
}
