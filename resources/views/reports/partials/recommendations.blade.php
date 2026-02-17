@php
    $recs = $data['recommendations'] ?? [];
    $lang = $language ?? 'ro';

    $categories = [
        'technical' => [
            'label' => __('report.rec_category_technical', [], $lang),
            'color' => '#2563eb',
            'items' => $recs['technical'] ?? [],
        ],
        'performance' => [
            'label' => __('report.rec_category_performance', [], $lang),
            'color' => '#0d9488',
            'items' => $recs['performance'] ?? [],
        ],
        'seo' => [
            'label' => __('report.rec_category_seo', [], $lang),
            'color' => '#10b981',
            'items' => $recs['seo'] ?? [],
        ],
    ];

    $hasAnyRecs = collect($categories)->sum(fn($c) => count($c['items'])) > 0;
@endphp

@if($hasAnyRecs)
    @include('reports.components.section-header', [
        'title' => __('report.section_recommendations', [], $lang),
    ])

    <p class="section-description">{{ __('report.recommendations_description', [], $lang) }}</p>

    @foreach($categories as $key => $category)
        @if(count($category['items']) > 0)
            <div class="rec-category-label" style="color: {{ $category['color'] }}; border-bottom: 2px solid {{ $category['color'] }};">
                {{ $category['label'] }}
            </div>

            @foreach($category['items'] as $rec)
                <div class="rec-card rec-priority-{{ $rec['priority'] ?? 'low' }}">
                    <div class="rec-title">{{ $rec['title'] }}</div>
                    <div class="rec-description">{{ $rec['description'] }}</div>
                </div>
            @endforeach
        @endif
    @endforeach
@endif
