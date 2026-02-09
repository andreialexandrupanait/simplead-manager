@props(['site', 'size' => 'md'])

@php
    $sizeClasses = match($size) {
        'sm' => 'h-5 w-5',
        'lg' => 'h-8 w-8',
        default => 'h-6 w-6',
    };

    $textSize = match($size) {
        'sm' => 'text-[8px]',
        'lg' => 'text-xs',
        default => 'text-[10px]',
    };

    $words = explode(' ', $site->name);
    $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
    $hue = abs(crc32($site->domain)) % 360;
@endphp

@if($site->favicon_path)
    <img src="{{ Storage::disk('public')->url($site->favicon_path) }}"
         alt=""
         class="{{ $sizeClasses }} rounded shrink-0"
         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
    <span class="{{ $sizeClasses }} rounded shrink-0 items-center justify-center font-bold text-white {{ $textSize }}"
          style="display:none;background:hsl({{ $hue }},50%,35%)">{{ $initials }}</span>
@else
    <span class="{{ $sizeClasses }} rounded shrink-0 inline-flex items-center justify-center font-bold text-white {{ $textSize }}"
          style="background:hsl({{ $hue }},50%,35%)">{{ $initials }}</span>
@endif
