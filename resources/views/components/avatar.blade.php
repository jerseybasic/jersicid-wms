@props(['user', 'size' => 'w-9 h-9 text-sm'])

{{-- Foto profil, atau inisial nama jika belum ada foto --}}
@if ($user?->profile_photo_url)
    <img src="{{ $user->profile_photo_url }}" alt="{{ $user->name }}"
        {{ $attributes->merge(['class' => "$size shrink-0 rounded-full object-cover border-2 border-cyan-500/30"]) }}>
@else
    <span {{ $attributes->merge(['class' => "$size shrink-0 rounded-full bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center text-on-accent font-bold shadow-lg shadow-cyan-500/30"]) }}>
        {{ $user?->initials ?? 'U' }}
    </span>
@endif
