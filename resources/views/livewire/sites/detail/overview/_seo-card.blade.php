@php
    $latestAudit = $site->latestSeoAudit;
    $score = $latestAudit?->score;
    $scoreColor = match(true) {
        $score === null => 'text-gray-400',
        $score >= 80    => 'text-green-600',
        $score >= 50    => 'text-yellow-500',
        default         => 'text-red-600',
    };
    $scoreBg = match(true) {
        $score === null => 'bg-gray-50',
        $score >= 80    => 'bg-green-50',
        $score >= 50    => 'bg-yellow-50',
        default         => 'bg-red-50',
    };
    $scoreRing = match(true) {
        $score === null => 'ring-gray-200',
        $score >= 80    => 'ring-green-300',
        $score >= 50    => 'ring-yellow-300',
        default         => 'ring-red-300',
    };
    $issueCounts = $latestAudit ? [
        'critical' => $latestAudit->critical_count,
        'high' => $latestAudit->high_count,
    ] : [];
@endphp

<x-ui.card :padding="false" class="flex flex-col">
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-3 py-2.5">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-purple-100">
                <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900">SEO</h3>
        </div>
        <a href="{{ route('sites.seo', $site) }}" class="text-xs text-purple-600 hover:text-purple-700">
            View Details &rarr;
        </a>
    </div>

    {{-- Card Content --}}
    <div class="flex flex-1 flex-col p-3">
        @if($latestAudit)
            <div class="flex items-center gap-3">
                {{-- Score circle --}}
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full ring-2 {{ $scoreBg }} {{ $scoreRing }}">
                    <span class="text-base font-bold {{ $scoreColor }}">{{ $score ?? '—' }}</span>
                </div>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900">
                        @if($score !== null)
                            @if($score >= 80) {{ __('Good') }}
                            @elseif($score >= 50) {{ __('Needs Attention') }}
                            @else {{ __('Poor') }}
                            @endif
                        @else
                            {{ __('No Score') }}
                        @endif
                    </p>
                    <p class="text-xs text-gray-500">{{ $latestAudit->created_at->diffForHumans() }}</p>
                </div>
            </div>

            {{-- Issue counts --}}
            @if(!empty($issueCounts))
                <div class="mt-3 flex gap-2">
                    @if(($issueCounts['critical'] ?? 0) > 0)
                        <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                            {{ $issueCounts['critical'] }} {{ __('critical') }}
                        </span>
                    @endif
                    @if(($issueCounts['high'] ?? 0) > 0)
                        <span class="inline-flex items-center rounded-full bg-orange-100 px-2 py-0.5 text-xs font-medium text-orange-700">
                            {{ $issueCounts['high'] }} {{ __('high') }}
                        </span>
                    @endif
                    @if(($issueCounts['critical'] ?? 0) === 0 && ($issueCounts['high'] ?? 0) === 0)
                        <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">
                            {{ __('No critical issues') }}
                        </span>
                    @endif
                </div>
            @endif

        @else
            <div class="py-2 text-center">
                <p class="text-sm text-gray-500">{{ __('Not audited yet') }}</p>
                <a href="{{ route('sites.seo', $site) }}" class="mt-1 inline-block text-xs text-purple-600 hover:text-purple-700">
                    {{ __('Run First Audit') }} &rarr;
                </a>
            </div>
        @endif
    </div>
</x-ui.card>
