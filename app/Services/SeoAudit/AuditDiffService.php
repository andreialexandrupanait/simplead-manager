<?php

declare(strict_types=1);

namespace App\Services\SeoAudit;

use App\Models\SeoAudit;

class AuditDiffService
{
    public function diff(SeoAudit $current, SeoAudit $previous): array
    {
        $ci = $current->issues()->get()->map(fn ($i) => $i->title.'|'.$i->url)->toArray();
        $pi = $previous->issues()->get()->map(fn ($i) => $i->title.'|'.$i->url)->toArray();

        return ['score_delta' => $current->score - $previous->score, 'pages_delta' => $current->pages_crawled - $previous->pages_crawled, 'new_issues' => count(array_diff($ci, $pi)), 'resolved_issues' => count(array_diff($pi, $ci))];
    }
}
