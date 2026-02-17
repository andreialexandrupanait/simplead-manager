{{-- Section title + accent line. Receives: $title, $number (optional) --}}
<div class="section-header">
    @if(isset($number))
        <div class="section-number">{{ $number }}</div>
    @endif
    <div class="section-header-title">{{ $title }}</div>
    <div class="section-header-line"></div>
</div>
