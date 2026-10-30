<x-admin-layout title="Edit Supplier">
    <x-slot name="pageTitle">Edit Supplier: {{ $supplier->name }}</x-slot>
    <div class="max-w-2xl mx-auto">
        <form action="{{ route('suppliers.update', $supplier) }}" method="POST" class="glass-panel p-6">
            @csrf @method('PUT')
            <div class="mb-4">
                <label class="form-label">Nama Supplier</label>
                <input type="text" name="name" required class="form-input" value="{{ old('name', $supplier->name) }}">
            </div>
            <div class="mb-4">
                <label class="form-label">Kontak Person</label>
                <input type="text" name="contact_person" class="form-input" value="{{ old('contact_person', $supplier->contact_person) }}">
            </div>
            <div class="mb-4">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" value="{{ old('email', $supplier->email) }}">
            </div>
            <div class="mb-4">
                <label class="form-label">No. Telepon</label>
                <input type="text" name="phone" class="form-input" value="{{ old('phone', $supplier->phone) }}">
            </div>
            <div class="mb-4">
                <label class="form-label">Alamat</label>
                <textarea name="address" class="form-input" rows="3">{{ old('address', $supplier->address) }}</textarea>
            </div>
            <div class="mb-4">
                <label class="form-label">Kota</label>
                <input type="text" name="city" class="form-input" value="{{ old('city', $supplier->city) }}">
            </div>
            <div class="mb-4">
                <label class="form-label">Lead Time (Hari)</label>
                <input type="number" name="lead_time_days" class="form-input" value="{{ old('lead_time_days', $supplier->lead_time_days) }}">
            </div>
            <div class="flex justify-end gap-3">
                <a href="{{ route('suppliers.index') }}" class="btn-ghost px-4 py-2 rounded-lg border-2 border-slate-600 bg-slate-800 text-slate-400 hover:bg-slate-700 hover:text-white">Batal</a>
                <button type="submit" class="bg-indigo-600 text-on-accent px-6 py-2 rounded-lg font-semibold border-2 border-indigo-600 hover:bg-indigo-500 hover:text-on-accent">Update</button>
            </div>
        </form>
    </div>
</x-admin-layout>