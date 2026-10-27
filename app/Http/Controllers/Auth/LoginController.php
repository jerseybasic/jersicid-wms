<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    /**
     * Tampilkan halaman login
     */
    public function showLoginForm()
    {
        return view('auth.login');
    }

    /**
     * Proses login
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $throttleKey = Str::transliterate(Str::lower($request->input('email')) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors([
                'email' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
            ])->onlyInput('email');
        }

        // Hanya user berstatus AKTIF yang boleh login
        if (Auth::attempt([...$credentials, 'status' => 'AKTIF'], $request->boolean('remember'))) {
            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();

            $user = Auth::user();
            AuditTrail::record('LOGIN', 'AUTH', ['email' => $user->email]);

            // Redirect berdasarkan role
            if ($user->role === 'OWNER') {
                return redirect()->intended(route('dashboard.owner'));
            } elseif ($user->role === 'STAFF_GUDANG') {
                // Staf gudang langsung ke Tugas: Picking, Packing, Serah Terima
                return redirect()->intended(route('gudang.tugas'));
            } else {
                // SUPERVISOR & STAFF_GUDANG
                return redirect()->intended(route('dashboard.gudang'));
            }
        }

        RateLimiter::hit($throttleKey, 60);
        AuditTrail::record('LOGIN_FAILED', 'AUTH', ['email' => Str::limit((string) $request->input('email'), 100, '')]);

        return back()->withErrors([
            'email' => 'Email atau password salah, atau akun belum aktif.',
        ])->onlyInput('email');
    }

    /**
     * Proses logout
     */
    public function logout(Request $request)
    {
        if (Auth::check()) {
            AuditTrail::record('LOGOUT', 'AUTH', ['email' => Auth::user()->email]);
        }
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
