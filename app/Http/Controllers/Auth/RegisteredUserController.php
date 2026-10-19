<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Events\UserRegistered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RegisteredUserController extends Controller
{
    public function create()
    {
        $roles = ['SUPERVISOR', 'STAFF_GUDANG', 'SUBLIM', 'KONVEKSI'];
        $workspaces = [
            'SUPERVISOR' => ['GUDANG'],
            'STAFF_GUDANG' => ['GUDANG', 'PACKING'],
            'SUBLIM' => ['PO'],
            'KONVEKSI' => ['PO'],
        ];

        return view('auth.register', compact('roles', 'workspaces'));
    }

    public function store(Request $request)
    {
        // Validasi
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role' => ['required', 'in:SUPERVISOR,STAFF_GUDANG,SUBLIM,KONVEKSI'],
            'workspace_default' => ['required', 'string'],
        ], [
            'password.min' => 'Password minimal 12 karakter',
            'password.confirmed' => 'Konfirmasi password tidak cocok',
            'role.in' => 'Role yang dipilih tidak valid',
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput()
                ->with('register_error', 'Registrasi gagal: ' . $validator->errors()->first());
        }

        try {
            // Buat user baru dengan UUID
            $user = User::create([
                'id' => (string) Str::uuid(),
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => $request->role,
                'workspace_default' => $request->workspace_default,
                'status' => 'NONAKTIF', // Perlu approval admin
            ]);
            event(new UserRegistered($user));

            // Log untuk debugging
            \Log::info('User registered successfully', ['user_id' => $user->id, 'email' => $user->email]);

            return redirect()->route('login')
                ->with('success', 'Registrasi berhasil! Akun Anda menunggu persetujuan admin. Silakan login setelah diaktifkan.');
        } catch (\Exception $e) {
            \Log::error('Registration failed', ['error' => $e->getMessage()]);

            return back()
                ->withInput()
                ->with('register_error', 'Terjadi kesalahan sistem: ' . safe_error_message($e));
        }
    }
}
