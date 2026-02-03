@php $p = $data['performance']; @endphp

<h2>Performanță</h2>

{{-- Two-column: Mobile | Desktop --}}
<table class="two-col">
    <tr>
        {{-- Mobile Card --}}
        <td class="perf-card">
            <div class="perf-card-title">Mobil</div>
            <div class="perf-card-subtitle">
                Performanță &bull;
                @if(isset($p['mobile']['tested_at']))
                    {{ \Carbon\Carbon::parse($p['mobile']['tested_at'])->format('d/m/Y') }}
                @else
                    —
                @endif
            </div>

            @php
                $mScore = $p['mobile_score'] ?? null;
                $mClass = $mScore === null ? 'score-na' : ($mScore >= 90 ? 'score-green' : ($mScore >= 50 ? 'score-orange' : 'score-red'));
            @endphp

            <div style="text-align: center; margin-bottom: 16px;">
                <div class="score-circle-lg {{ $mClass }}">{{ $mScore ?? '—' }}</div>
            </div>

            @php
                $vitals = [
                    'fcp' => 'FCP',
                    'si' => 'Speed Index',
                    'lcp' => 'LCP',
                    'tbt' => 'TBT',
                    'cls' => 'CLS',
                ];
            @endphp

            <table class="perf-metric-row">
                @foreach($vitals as $key => $label)
                    <tr>
                        <td>
                            @php $color = $p['mobile'][$key . '_color'] ?? 'gray'; @endphp
                            @if($color === 'green')
                                <span class="perf-indicator good">&#9679;</span>
                            @elseif($color === 'orange')
                                <span class="perf-indicator moderate">&#9632;</span>
                            @else
                                <span class="perf-indicator poor">&#9650;</span>
                            @endif
                            {{ $label }}
                        </td>
                        <td style="text-align: right; font-weight: 600;">
                            {{ $p['mobile'][$key] ?? '—' }}
                        </td>
                    </tr>
                @endforeach
            </table>
        </td>

        {{-- Desktop Card --}}
        <td class="perf-card">
            <div class="perf-card-title">Desktop</div>
            <div class="perf-card-subtitle">
                Performanță &bull;
                @if(isset($p['desktop']['tested_at']))
                    {{ \Carbon\Carbon::parse($p['desktop']['tested_at'])->format('d/m/Y') }}
                @else
                    —
                @endif
            </div>

            @php
                $dScore = $p['desktop_score'] ?? null;
                $dClass = $dScore === null ? 'score-na' : ($dScore >= 90 ? 'score-green' : ($dScore >= 50 ? 'score-orange' : 'score-red'));
            @endphp

            <div style="text-align: center; margin-bottom: 16px;">
                <div class="score-circle-lg {{ $dClass }}">{{ $dScore ?? '—' }}</div>
            </div>

            <table class="perf-metric-row">
                @foreach($vitals as $key => $label)
                    <tr>
                        <td>
                            @php $color = $p['desktop'][$key . '_color'] ?? 'gray'; @endphp
                            @if($color === 'green')
                                <span class="perf-indicator good">&#9679;</span>
                            @elseif($color === 'orange')
                                <span class="perf-indicator moderate">&#9632;</span>
                            @else
                                <span class="perf-indicator poor">&#9650;</span>
                            @endif
                            {{ $label }}
                        </td>
                        <td style="text-align: right; font-weight: 600;">
                            {{ $p['desktop'][$key] ?? '—' }}
                        </td>
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>

{{-- Legend --}}
<div class="perf-legend">
    <span class="perf-indicator poor">&#9650;</span> 0–49
    &nbsp;&nbsp;&nbsp;
    <span class="perf-indicator moderate">&#9632;</span> 50–89
    &nbsp;&nbsp;&nbsp;
    <span class="perf-indicator good">&#9679;</span> 90–100
</div>
