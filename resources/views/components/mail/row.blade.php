@props([
    'label',
    'tone' => null,
])

@php
    $tones = [
        'danger' => '#b32d2e',
        'success' => '#0a7c2f',
        'warning' => '#8a6100',
    ];
    $valueColor = $tones[$tone] ?? '#1f2937';
@endphp

<tr>
    <td valign="top" style="padding:11px 0; border-bottom:1px solid #eef1f5; font-size:14px; line-height:21px; color:#6b7280;">{{ $label }}</td>
    <td valign="top" align="right" style="padding:11px 0; border-bottom:1px solid #eef1f5; font-size:14px; line-height:21px; font-weight:600; color:{{ $valueColor }};">{{ $slot }}</td>
</tr>
