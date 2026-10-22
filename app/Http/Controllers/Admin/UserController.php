<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $users = User::orderBy('created_at', 'desc')->paginate(15);

        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        return view('admin.users.create');
    }

    public function store(Request $request)
    {
        // Nomor boleh diketik dengan spasi atau tanda hubung
        $request->merge(['whatsapp' => preg_replace('/[\s\-().]/', '', (string) $request->input('whatsapp')) ?: null]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z\s]+$/'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role' => ['required', 'in:OWNER,SUPERVISOR,ADMIN_GUDANG,STAFF_GUDANG,SUBLIM,KONVEKSI'],
            'workspace_default' => ['required', 'in:UTAMA,GUDANG,PACKING,PO'],
            'status' => ['required', 'in:AKTIF,NONAKTIF'],
            'whatsapp' => ['nullable', 'string', 'regex:/^(\+?62|0)8\d{7,12}$/'],
            'notify_whatsapp' => ['nullable', 'boolean'],
        ], [
            'name.regex' => 'Nama hanya boleh mengandung huruf dan spasi.',
            'password.min' => 'Password minimal 12 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'whatsapp.regex' => 'Nomor WhatsApp harus nomor HP Indonesia, contoh 081234567890.',
        ]);

        User::create([
            'id' => (string) Str::uuid(),
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'workspace_default' => $validated['workspace_default'],
            'status' => $validated['status'],
            'whatsapp' => $validated['whatsapp'] ?? null,
            'notify_whatsapp' => $request->boolean('notify_whatsapp') && !empty($validated['whatsapp']),
        ]);

        return redirect()->route('users.index')
            ->with('success', 'User berhasil ditambahkan!');
    }

    public function edit($id)
    {
        $user = User::findOrFail($id);
        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // Nomor boleh diketik dengan spasi atau tanda hubung
        $request->merge(['whatsapp' => preg_replace('/[\s\-().]/', '', (string) $request->input('whatsapp')) ?: null]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z\s]+$/'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role' => ['required', 'in:OWNER,SUPERVISOR,ADMIN_GUDANG,STAFF_GUDANG,SUBLIM,KONVEKSI'],
            'workspace_default' => ['required', 'in:UTAMA,GUDANG,PACKING,PO'],
            'status' => ['required', 'in:AKTIF,NONAKTIF'],
            'whatsapp' => ['nullable', 'string', 'regex:/^(\+?62|0)8\d{7,12}$/'],
            'notify_whatsapp' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:12', 'confirmed'],
        ], [
            'name.regex' => 'Nama hanya boleh mengandung huruf dan spasi.',
            'password.min' => 'Password minimal 12 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'whatsapp.regex' => 'Nomor WhatsApp harus nomor HP Indonesia, contoh 081234567890.',
        ]);

        $updateData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'workspace_default' => $validated['workspace_default'],
            'status' => $validated['status'],
            'whatsapp' => $validated['whatsapp'] ?? null,
            'notify_whatsapp' => $request->boolean('notify_whatsapp') && !empty($validated['whatsapp']),
        ];

        if (!empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        $user->update($updateData);

        return redirect()->route('users.index')
            ->with('success', 'User berhasil diperbarui!');
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);

        // Cegah hapus diri sendiri
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Anda tidak dapat menghapus akun sendiri!');
        }

        $user->delete();

        return redirect()->route('users.index')
            ->with('success', 'User berhasil dihapus!');
    }

    public function toggleStatus($id)
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            return back()->with('error', 'Tidak dapat mengubah status akun sendiri!');
        }

        $newStatus = $user->status === 'AKTIF' ? 'NONAKTIF' : 'AKTIF';
        $user->update(['status' => $newStatus]);

        return back()->with('success', "Status user {$user->name} diubah menjadi {$newStatus}!");
    }
}
