{{-- Styled data table wrapper. Use as: @include with $headers (array) and $slot or just wrap content --}}
<table class="data-table {{ $class ?? '' }}">
    @if(isset($headers))
        <thead>
            <tr>
                @foreach($headers as $header)
                    <th style="{{ $header['style'] ?? '' }}">{{ $header['label'] }}</th>
                @endforeach
            </tr>
        </thead>
    @endif
    <tbody>
        {{ $slot ?? '' }}
    </tbody>
</table>
