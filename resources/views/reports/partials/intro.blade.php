@php
    $lang = $language ?? 'ro';
@endphp

<div class="intro-title">
    {!! nl2br(e(__('report.intro_title', [], $lang))) !!}
</div>

<div class="intro-body">
    {!! nl2br(e($introText)) !!}
</div>

<div class="intro-sections-title">{{ __('report.intro_sections_included', [], $lang) }}</div>

<table style="width: 70%; border-collapse: collapse;">
    @foreach($sections as $section)
        @php $sectionLabel = __('report.section_label_' . $section, [], $lang); @endphp
        @if($sectionLabel !== 'report.section_label_' . $section)
            <tr>
                <td class="intro-section-item">
                    <span class="intro-section-check" style="color: #2563eb;">&#10003;</span>
                    {{ $sectionLabel }}
                </td>
            </tr>
        @endif
    @endforeach
</table>
