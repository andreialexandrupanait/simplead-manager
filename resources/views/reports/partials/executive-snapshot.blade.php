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
    {{-- Hero row: Uptime + Updates (first 2 cards, 50/50) --}}
    <div class="snapshot-hero-grid">
        @foreach(array_slice($snapshot, 0, 2) as $card)
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

    {{-- Row 2: Downtime | Backups | Desktop Score (3 columns) --}}
    <div class="snapshot-grid">
        @foreach(array_slice($snapshot, 2, 3) as $card)
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

    {{-- Row 3: Mobile Score | Users | Impressions (3 columns) --}}
    <div class="snapshot-grid">
        @foreach(array_slice($snapshot, 5, 3) as $card)
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
@endif
