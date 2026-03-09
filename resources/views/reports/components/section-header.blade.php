{{-- Section title with number + full-width border. Receives: $title, $number (optional) --}}
<div class="section-header">
    <div class="section-number-title">
        @if(isset($number))
            <span class="section-number">{{ str_pad($number, 2, '0', STR_PAD_LEFT) }}</span>
        @endif
        <h2 class="section-header-title">{{ $title }}</h2>
    </div>
    <div class="section-border"></div>
</div>
