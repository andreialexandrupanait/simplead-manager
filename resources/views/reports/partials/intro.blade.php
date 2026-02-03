<div class="intro-title">
    Raport lunar de<br>
    Mentenanță și<br>
    Performanță website
</div>

<div class="intro-body">
    @if($template->intro_text)
        {!! nl2br(e($template->intro_text)) !!}
    @else
        Acest raport oferă o privire de ansamblu completă asupra sănătății, performanței și activităților de mentenanță ale website-ului dumneavoastră pentru perioada de raportare. Toate datele sunt colectate automat din sistemele de monitorizare ale site-ului.
    @endif
</div>

<div class="intro-sections-title">Secțiuni incluse</div>

@php
    $sectionLabels = [
        'overview' => 'Privire de ansamblu',
        'updates' => 'Actualizări WordPress',
        'uptime' => 'Monitorizare timp de funcționare',
        'backups' => 'Copii de rezervă',
        'analytics' => 'Google Analytics',
        'search_console' => 'Google Console de Căutare',
        'performance' => 'Performanță (PageSpeed)',
        'links' => 'Link-uri verificate',
    ];
@endphp

<table style="width: 60%; border-collapse: collapse;">
    @foreach($template->sections ?? [] as $section)
        <tr>
            <td class="intro-section-item">
                <span class="intro-section-check">&#10003;</span>
                {{ $sectionLabels[$section] ?? ucfirst($section) }}
            </td>
        </tr>
    @endforeach
</table>

@if($template->company_website)
    <div class="mt-8 text-muted text-sm">
        {{ $template->company_name ?? '' }} &mdash; {{ $template->company_website }}
    </div>
@endif
