@php
    $performanceUrl = route('sites.performance', $site);
    $count = count($violations);
@endphp

<x-mail.layout
    :title="__('Performance budget exceeded on :site', ['site' => $site->name])"
    :preheader="trans_choice('{1}:count metric is over budget.|[2,*]:count metrics are over budget.', $count, ['count' => $count])"
    :message="$message ?? null"
    state-color="#d63638">

    <x-mail.heading>{{ trans_choice('{1}:site is over its performance budget on :count metric|[2,*]:site is over its performance budget on :count metrics', $count, ['site' => $site->name, 'count' => $count]) }}</x-mail.heading>

    <p>{{ __('These are the thresholds you set for this site. Each one below is measured against the budget it broke.') }}</p>

    <x-mail.details>
        @foreach($violations as $violation)
            <x-mail.row :label="$violation['key']" tone="danger">
                {{ $violation['actual'] }} <span style="color:#9199a8; font-weight:400;">/ {{ $violation['budget'] }}</span>
            </x-mail.row>
        @endforeach
    </x-mail.details>

    <p>{{ __('If the budget itself has become unrealistic for this site, it is worth raising rather than muting — a budget nobody believes stops being useful.') }}</p>

    <x-mail.button :href="$performanceUrl">{{ __('Open performance') }}</x-mail.button>

    <x-slot:footer>
        {{ __('Measured for :site.', ['site' => $site->url]) }}
    </x-slot:footer>
</x-mail.layout>
