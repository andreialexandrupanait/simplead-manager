@php
    $lang = $language ?? 'ro';

    // Prefer approved DB recommendations (from the approval UI), fall back to auto-generated array
    $useApproved = isset($data['recommendations_approved']) && $data['recommendations_approved']->count() > 0;

    $allRecs = [];

    if ($useApproved) {
        // DB collection → flat array with priority grouping
        foreach ($data['recommendations_approved'] as $rec) {
            $allRecs[] = [
                'title' => $rec->title,
                'description' => $rec->description,
                'priority' => $rec->priority,
                'category' => $rec->category,
            ];
        }
    } else {
        // Auto-generated array (grouped by category)
        $recs = $data['recommendations'] ?? [];
        foreach (['technical', 'performance', 'seo'] as $cat) {
            foreach ($recs[$cat] ?? [] as $rec) {
                $allRecs[] = array_merge($rec, ['category' => $cat]);
            }
        }
    }

    // Group by priority: high → medium → low/info
    $priorityGroups = [
        'high' => ['label' => __('report.priority_high', [], $lang), 'color' => '#ef4444', 'border' => '#ef4444', 'items' => []],
        'medium' => ['label' => __('report.priority_medium', [], $lang), 'color' => '#f59e0b', 'border' => '#f59e0b', 'items' => []],
        'low' => ['label' => __('report.priority_info', [], $lang), 'color' => '#64748b', 'border' => '#94a3b8', 'items' => []],
    ];

    foreach ($allRecs as $rec) {
        $p = $rec['priority'] ?? 'medium';
        if (!isset($priorityGroups[$p])) $p = 'medium';
        $priorityGroups[$p]['items'][] = $rec;
    }

    $hasAnyRecs = count($allRecs) > 0;
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['recommendations']['title'] ?? __('report.section_recommendations', [], $lang),
    'number' => $sectionNumber ?? null,
])

<p class="section-description">{{ $sectionOverrides['recommendations']['description'] ?? __('report.recommendations_description', [], $lang) }}</p>

@if($hasAnyRecs)
    @php $recNumber = 1; @endphp

    @foreach($priorityGroups as $priority => $group)
        @if(count($group['items']) > 0)
            <div class="rec-category-label" style="color: {{ $group['color'] }}; border-bottom: 2px solid {{ $group['color'] }};">
                {{ $group['label'] }}
            </div>

            @foreach($group['items'] as $rec)
                <div class="rec-card" style="border-left: 6px solid {{ $group['border'] }};">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                        <span style="font-size: 8pt; font-weight: 700; color: {{ $group['color'] }}; min-width: 18px;">{{ $recNumber }}.</span>
                        <div class="rec-title" style="flex: 1;">{{ $rec['title'] }}</div>
                        @php
                            $pillClass = match($rec['priority'] ?? 'low') {
                                'high' => 'badge-pill-high',
                                'medium' => 'badge-pill-medium',
                                default => 'badge-pill-low',
                            };
                        @endphp
                        <span class="badge-pill {{ $pillClass }}">{{ ucfirst($rec['priority'] ?? 'low') }}</span>
                    </div>
                    <div class="rec-description" style="padding-left: 26px;">{{ $rec['description'] }}</div>
                </div>
                @php $recNumber++; @endphp
            @endforeach
        @endif
    @endforeach
@else
    <div style="text-align: center; padding: 20px 0; color: #10b981;">
        <div style="font-size: 14pt; font-weight: 700; margin-bottom: 6px;">&#10003;</div>
        <p style="font-size: 9pt; color: #64748b;">{{ __('report.no_recommendations', [], $lang) }}</p>
    </div>
@endif
