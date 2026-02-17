{{-- Page header — repeated HTML in each .page div (not on cover). --}}
<div class="page-header">
    <table style="border-bottom: 2px solid {{ $primaryColor }};">
        <tr>
            <td style="text-align: left; width: 50%;">
                @if($clientLogo && file_exists($clientLogo))
                    <img src="{{ $clientLogo }}" class="header-logo" alt="">
                @else
                    <span style="font-weight: 600; font-size: 9pt; color: #111827;">{{ $site->name }}</span>
                @endif
            </td>
            <td class="header-title" style="width: 50%;">
                {{ __('report.title', [], $lang) }} &bull; {{ $periodStart->format('d/m/Y') }} &ndash; {{ $periodEnd->format('d/m/Y') }}
            </td>
        </tr>
    </table>
</div>
