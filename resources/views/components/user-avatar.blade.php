@props(['user', 'size' => 'md', 'rounded' => 'rounded-xl'])

@php
    $sizeClass = [
        'sm' => 'size-10 text-xs',
        'md' => 'size-11 text-sm',
        'lg' => 'size-12 text-sm',
        'xl' => 'size-14 text-base',
    ][$size] ?? 'size-11 text-sm';
    $name = (string) ($user?->name ?? 'Utilisateur');
    $photo = $user?->profile_photo_path ? asset('storage/'.$user->profile_photo_path) : null;
    $initials = Str::upper(Str::substr($name, 0, 2));
@endphp

<span
    {{ $attributes->merge(['class' => 'grid shrink-0 place-items-center overflow-hidden '.$sizeClass.' '.$rounded.' font-bold text-white shadow-sm']) }}
    @unless ($photo)
        style="background: {{ $user?->avatar_color ?: 'var(--brand-primary)' }}"
    @endunless
>
    @if ($photo)
        <img src="{{ $photo }}" alt="{{ $name }}" class="h-full w-full object-cover">
    @else
        {{ $initials }}
    @endif
</span>
