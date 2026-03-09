@php
    $snapshot = $data['executive_snapshot'] ?? [];
    $lang = $language ?? 'ro';
    $noDataLabel = __('report.snapshot_no_data', [], $lang);
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['executive_snapshot']['title'] ?? __('report.section_executive_snapshot', [], $lang),
])

<p class="section-description">{{ $sectionOverrides['executive_snapshot']['description'] ?? __('report.executive_snapshot_description', [], $lang) }}</p>

@if(!empty($snapshot))
    @php
        $totalCards = count($snapshot);
        // First 2 cards are hero-sized, rest in rows of 3
        $heroCards = array_slice($snapshot, 0, min(2, $totalCards));
        $remainingCards = $totalCards > 2 ? array_slice($snapshot, 2) : [];
        $rows = array_chunk($remainingCards, 3);
    @endphp

    {{-- Hero row: first 2 cards (50/50) --}}
    @if(count($heroCards) > 0)
        <div class="snapshot-hero-grid">
            @foreach($heroCards as $card)
                @php $isMuted = in_array($card['value'], [$noDataLabel, 'Fără date', 'No data', '—', 'Fara date']); @endphp
                <div class="snapshot-hero-card snapshot-status-{{ $card['status'] ?? 'neutral' }}">
                    <div class="snapshot-hero-value {{ $isMuted ? 'value-muted' : '' }}">{{ $isMuted ? '—' : $card['value'] }}</div>
                    <div class="snapshot-label">{{ $card['label'] }}</div>
                    @if(!empty($card['note']))
                        <div class="snapshot-note">{{ $card['note'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Remaining cards in rows of 3 --}}
    @foreach($rows as $row)
        <div class="snapshot-grid">
            @foreach($row as $card)
                @php $isMuted = in_array($card['value'], [$noDataLabel, 'Fără date', 'No data', '—', 'Fara date']); @endphp
                <div class="snapshot-card snapshot-status-{{ $card['status'] ?? 'neutral' }}">
                    <div class="snapshot-value {{ $isMuted ? 'value-muted' : '' }}">{{ $isMuted ? '—' : $card['value'] }}</div>
                    <div class="snapshot-label">{{ $card['label'] }}</div>
                    @if(!empty($card['note']))
                        <div class="snapshot-note">{{ $card['note'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
@endif
