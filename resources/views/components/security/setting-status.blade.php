@props(['status' => null])

@php
    use App\Enums\SecuritySettingStatus;

    if (is_string($status)) {
        $status = SecuritySettingStatus::tryFrom($status);
    }

    if (!$status instanceof SecuritySettingStatus || $status === SecuritySettingStatus::NotConfigured) {
        return;
    }

    $color = $status->color();
@endphp

<span class="inline-flex items-center gap-1 text-[10px] text-{{ $color }}-600">
    <span class="h-1.5 w-1.5 rounded-full bg-{{ $color }}-500"></span>
    {{ $status->label() }}
</span>
