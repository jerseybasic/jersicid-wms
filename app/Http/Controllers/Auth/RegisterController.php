<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    /**
     * Tampilkan halaman registrasi
     */
    public function showRegistrationForm()
    {
        // Hanya role tertentu yang boleh registrasi mandiri (OWNER dibuat manual oleh sistem)
        $roles = ['SUPERVISOR', 'STAFF_GUDANG', 'SUBLIM', 'KONVEKSI'];

        return view('auth.register', compact('roles'));
    }

    /**
     * Proses registrasi
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'in:SUPERVISOR,STAFF_GUDANG,SUBLIM,KONVEKSI'],
            'workspace_default' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'min:12',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{12,}$/'
            ],
        ], [
            'password.regex' => 'Password harus minimal 12 karakter, mengandung huruf besar, huruf kecil, angka, dan simbol (@$!%*?&).',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Buat user baru (menggunakan UUID sesuai PRD)
        User::create([
            'id' => (string) Str::uuid(),
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'workspace_default' => $request->workspace_default,
            // Akun baru menunggu aktivasi OWNER sebelum bisa login
            'status' => 'NONAKTIF',
        ]);

        return redirect()->route('login')->with('success', 'Akun berhasil dibuat dan menunggu aktivasi oleh OWNER. Hubungi OWNER agar akun Anda diaktifkan.');
    }
}
