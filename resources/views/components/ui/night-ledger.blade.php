@props([
    /** list<array{date: string, state: int}> — oldest first, right edge is last night. */
    'nights' => [],
    'label' => null,
])

@php
    use App\Services\Backup\FleetLedger;

    $counts = array_count_values(array_column($nights, 'state'));
    $verified = $counts[FleetLedger::VERIFIED] ?? 0;
    $unverified = $counts[FleetLedger::UNVERIFIED] ?? 0;
    $failed = $counts[FleetLedger::FAILED] ?? 0;

    // One label for the strip, not thirty. A screen reader announcing "night of 3 August, nothing"
    // thirty times per row, forty-two rows down the page, is worse than useless — the summary is
    // what a sighted reader takes from the shape anyway.
    $summary = $label ?? __(':points restore points, :failed failed runs, over the last :nights nights', [
        'points' => $verified + $unverified,
        'failed' => $failed,
        'nights' => count($nights),
    ]);
@endphp

<div
    {{ $attributes->merge(['class' => 'flex items-center gap-px']) }}
    role="img"
    aria-label="{{ $summary }}"
>
    @foreach($nights as $i => $night)
        @php
            $date = \Illuminate\Support\Carbon::parse($night['date']);

            [$classes, $title] = match ($night['state']) {
                FleetLedger::VERIFIED => [
                    'bg-accent-500',
                    __(':date — restore point, verified', ['date' => $date->isoFormat('D MMM')]),
                ],
                FleetLedger::UNVERIFIED => [
                    'border border-accent-400 bg-accent-100',
                    __(':date — restore point, not checked yet', ['date' => $date->isoFormat('D MMM')]),
                ],
                FleetLedger::FAILED => [
                    'sad-night-failed bg-red-500',
                    __(':date — the run failed', ['date' => $date->isoFormat('D MMM')]),
                ],
                default => [
                    'border border-gray-200',
                    __(':date — nothing ran', ['date' => $date->isoFormat('D MMM')]),
                ],
            };

            // Below `sm` only the last fortnight fits. Dropping the oldest cells keeps the right
            // edge — last night — anchored, which is the end that carries the meaning.
            $responsive = $i < (count($nights) - 14) ? 'hidden sm:block' : '';
        @endphp
        <span
            class="h-4 w-2 shrink-0 rounded-[1px] {{ $classes }} {{ $responsive }}"
            title="{{ $title }}"
        ></span>
    @endforeach
</div>
