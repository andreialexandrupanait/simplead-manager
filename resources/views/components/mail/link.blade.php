@props(['href'])

@php
    $accent = app(\App\Services\Branding\EmailBrandingService::class)->accentColor();
@endphp

{{-- Colour is inlined rather than left to the <style> block: Gmail keeps head
     styles, but Yahoo and several mobile clients drop them, and an unstyled
     link inside prose reads as plain text. --}}
<a href="{{ $href }}" style="color:{{ $accent }}; text-decoration:none; font-weight:500;">{{ $slot }}</a>
