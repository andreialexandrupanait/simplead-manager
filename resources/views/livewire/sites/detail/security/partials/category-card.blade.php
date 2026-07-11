{{-- Category status card: icon chip colored by status + counts + progress + badge.
     Expects: $site, $label, $route, $icon, $settings (Collection of SecuritySetting). --}}
@php
    $enabledCount = $settings->where('is_enabled', true)->count();
    $appliedCount = $settings->where('status', \App\Enums\SecuritySettingStatus::Applied)->count();
    $failedCount = $settings->where('status', \App\Enums\SecuritySettingStatus::Failed)->count();
    $totalCount = $settings->count();

    $statusColor = $failedCount > 0 ? 'red'
        : ($appliedCount > 0 && $appliedCount === $enabledCount ? 'green'
        : ($enabledCount > 0 ? 'yellow' : 'gray'));

    $chipBg = match ($statusColor) {
        'red' => 'bg-red-50 text-red-600',
        'green' => 'bg-green-50 text-green-600',
        'yellow' => 'bg-yellow-50 text-yellow-600',
        default => 'bg-gray-100 text-gray-500',
    };
    $barColor = match ($statusColor) {
        'red' => 'bg-red-500',
        'green' => 'bg-green-500',
        'yellow' => 'bg-yellow-500',
        default => 'bg-gray-300',
    };
    $progress = $enabledCount > 0 ? (int) round($appliedCount / $enabledCount * 100) : 0;
@endphp

<a href="{{ route($route, $site) }}">
    <x-ui.card class="h-full cursor-pointer hover:border-accent-200 transition-colors">
        <div class="flex items-start gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $chipBg }}">
                <x-dynamic-component :component="'icons.' . $icon" class="h-5 w-5" aria-hidden="true" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-start justify-between gap-2">
                    <h4 class="text-sm font-semibold text-gray-900">{{ $label }}</h4>
                    @if($failedCount > 0)
                        <x-ui.badge variant="red">{{ __('Needs Attention') }}</x-ui.badge>
                    @elseif($appliedCount > 0 && $appliedCount === $enabledCount)
                        <x-ui.badge variant="green">{{ __('Applied') }}</x-ui.badge>
                    @elseif($enabledCount > 0)
                        <x-ui.badge variant="yellow">{{ __('Pending') }}</x-ui.badge>
                    @else
                        <x-ui.badge variant="gray">{{ __('Not Configured') }}</x-ui.badge>
                    @endif
                </div>
                <p class="mt-1 text-xs text-gray-500">
                    @if($totalCount === 0)
                        {{ __('Not configured') }}
                    @else
                        {{ $appliedCount }}/{{ $enabledCount }} {{ __('applied') }}
                    @endif
                </p>
                @if($totalCount > 0)
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full rounded-full {{ $barColor }} transition-all" style="width: {{ $progress }}%"></div>
                    </div>
                @endif
            </div>
        </div>
    </x-ui.card>
</a>
