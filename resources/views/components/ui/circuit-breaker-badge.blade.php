@props(['site'])

@php
    $health = $site->healthState;
    if (!$health) return;

    $state = $health->circuit_state;
    $disabled = $health->is_monitoring_disabled;

    if ($disabled) {
        $color = 'gray';
        $label = 'Paused';
    } elseif ($state === 'open') {
        $color = 'red';
        $label = 'Circuit Open';
    } elseif ($state === 'half_open') {
        $color = 'yellow';
        $label = 'Recovering';
    } else {
        return; // closed = normal, no badge needed
    }
@endphp

<x-ui.badge :variant="$color">{{ $label }}</x-ui.badge>
