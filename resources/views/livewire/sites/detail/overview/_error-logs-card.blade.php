@php
    $errorLog = $this->errorLogStatus;
    $errTone = $errorLog['fatal'] > 0 ? 'bad' : 'dark';
@endphp

<x-ui.module-card title="Error Logs" icon="alert-triangle" :tone="$errTone">
    {{-- Fatal count --}}
    <x-ui.info-row label="Fatal Errors">
        @if($errorLog['fatal'] > 0)
            <span class="mval-bad font-bold">{{ $errorLog['fatal'] }}</span>
        @else
            <span class="mval-ok">None</span>
        @endif
    </x-ui.info-row>

    {{-- Total unresolved --}}
    <x-ui.info-row label="Unresolved">{{ $errorLog['total'] }}</x-ui.info-row>

    @if($errorLog['total'] > 0)
        <a href="{{ route('error-logs.index', ['site' => $site->id]) }}" class="mact">View All Errors →</a>
    @endif
</x-ui.module-card>
