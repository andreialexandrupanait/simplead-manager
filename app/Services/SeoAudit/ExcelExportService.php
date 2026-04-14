<?php

declare(strict_types=1);

namespace App\Services\SeoAudit;

use App\Models\SeoAudit;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelExportService
{
    private const HEADER_BG = 'FF2D3748';
    private const HEADER_FG = 'FFFFFFFF';
    private const SECTION_BG = 'FFF3F4F6';
    private const CRITICAL_BG = 'FFFEE2E2';
    private const HIGH_BG = 'FFFFF7ED';
    private const MEDIUM_BG = 'FFFFFBEB';
    private const LOW_BG = 'FFEFF6FF';
    private const GREEN_FG = 'FF059669';
    private const RED_FG = 'FFDC2626';
    private const YELLOW_FG = 'FFD97706';

    public function export(SeoAudit $audit): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $this->buildSummarySheet($spreadsheet, $audit);
        $this->buildIssuesSheet($spreadsheet, $audit);
        $this->buildPagesSheet($spreadsheet, $audit);
        $this->buildBrokenLinksSheet($spreadsheet, $audit);
        $this->buildBrokenImagesSheet($spreadsheet, $audit);
        $this->buildRedirectsSheet($spreadsheet, $audit);
        $this->buildImagesOverviewSheet($spreadsheet, $audit);
        $this->buildLinksMapSheet($spreadsheet, $audit);
        $this->buildInfrastructureSheet($spreadsheet, $audit);

        $spreadsheet->setActiveSheetIndex(0);

        $path = storage_path('app/temp/seo-audit-'.($audit->site?->domain ?? 'export').'-'.now()->format('Y-m-d').'.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    private function buildSummarySheet(Spreadsheet $spreadsheet, SeoAudit $audit): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Summary');

        $site = $audit->site;
        $cats = $audit->category_scores ?? [];
        $ssl = $audit->ssl_info ?? [];
        $headers = $audit->security_headers ?? [];
        $robots = $audit->robots_txt_data ?? [];
        $sitemapData = $audit->data['sitemap'] ?? [];
        $diff = $audit->data['diff'] ?? [];

        $row = 1;

        // Title
        $sheet->setCellValue("A{$row}", 'SEO Audit Report');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16);
        $row += 2;

        // Site info
        $this->writeSectionHeader($sheet, $row, 'Site Information');
        $row++;
        $info = [
            ['Site URL', $site?->url ?? 'N/A'],
            ['Site Name', $site?->name ?? 'N/A'],
            ['Scan Date', $audit->scanned_at?->format('M d, Y H:i') ?? 'N/A'],
            ['Duration', $audit->scan_duration ? gmdate('H:i:s', $audit->scan_duration) : 'N/A'],
            ['Pages Crawled', $audit->pages_crawled],
            ['SEO Plugin', ($audit->seo_plugin ?? 'None detected') . ($audit->seo_plugin_version ? ' v'.$audit->seo_plugin_version : '')],
        ];
        foreach ($info as $item) {
            $sheet->setCellValue("A{$row}", $item[0]);
            $sheet->setCellValue("B{$row}", $item[1]);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }
        $row++;

        // Scores
        $this->writeSectionHeader($sheet, $row, 'Scores');
        $row++;
        $scores = [
            ['Overall Score', $audit->score],
            ['Technical SEO (40%)', $cats['technical'] ?? 'N/A'],
            ['On-Page (30%)', $cats['on_page'] ?? 'N/A'],
            ['Performance (20%)', $cats['performance'] ?? 'N/A'],
            ['Other (10%)', $cats['other'] ?? 'N/A'],
        ];
        foreach ($scores as $item) {
            $sheet->setCellValue("A{$row}", $item[0]);
            $sheet->setCellValue("B{$row}", $item[1]);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            if (is_numeric($item[1])) {
                $c = $item[1] >= 80 ? self::GREEN_FG : ($item[1] >= 50 ? self::YELLOW_FG : self::RED_FG);
                $sheet->getStyle("B{$row}")->getFont()->setBold(true)->setColor(new Color($c));
            }
            $row++;
        }

        // Score vs previous
        if (! empty($diff)) {
            $delta = $diff['score_delta'] ?? 0;
            $sheet->setCellValue("A{$row}", 'vs Previous');
            $sheet->setCellValue("B{$row}", ($delta > 0 ? '+' : '') . $delta . ' points');
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("B{$row}")->getFont()->setColor(new Color($delta > 0 ? self::GREEN_FG : ($delta < 0 ? self::RED_FG : 'FF6B7280')));
            $row++;
        }
        $row++;

        // Issue summary
        $this->writeSectionHeader($sheet, $row, 'Issue Summary');
        $row++;
        $issues = [
            ['Critical', $audit->critical_count, self::RED_FG],
            ['High', $audit->high_count, 'FFEA580C'],
            ['Medium', $audit->medium_count, self::YELLOW_FG],
            ['Low', $audit->low_count, 'FF2563EB'],
            ['Info', $audit->info_count, 'FF6B7280'],
            ['Total', $audit->totalIssues(), null],
        ];
        foreach ($issues as $item) {
            $sheet->setCellValue("A{$row}", $item[0]);
            $sheet->setCellValue("B{$row}", $item[1]);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            if ($item[2] && $item[1] > 0) {
                $sheet->getStyle("B{$row}")->getFont()->setBold(true)->setColor(new Color($item[2]));
            }
            $row++;
        }
        $row++;

        // Resource counts
        $this->writeSectionHeader($sheet, $row, 'Resource Health');
        $row++;
        $resources = [
            ['Broken Links', $audit->broken_links_count ?? 0],
            ['Broken Images', $audit->broken_images_count ?? 0],
            ['Total Images Tracked', $audit->total_images_count ?? 0],
            ['Redirect Pages', $audit->redirect_pages_count ?? 0],
        ];
        foreach ($resources as $item) {
            $sheet->setCellValue("A{$row}", $item[0]);
            $sheet->setCellValue("B{$row}", $item[1]);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            if ($item[1] > 0 && str_contains($item[0], 'Broken')) {
                $sheet->getStyle("B{$row}")->getFont()->setColor(new Color(self::RED_FG));
            }
            $row++;
        }
        $row++;

        // SSL
        $this->writeSectionHeader($sheet, $row, 'SSL Certificate');
        $row++;
        $sslData = [
            ['Valid', ($ssl['valid'] ?? false) ? 'Yes' : 'No'],
            ['Expiry', $ssl['expiry'] ?? 'N/A'],
            ['Days Until Expiry', $ssl['days_until_expiry'] ?? 'N/A'],
            ['Issuer', $ssl['issuer'] ?? 'N/A'],
        ];
        foreach ($sslData as $item) {
            $sheet->setCellValue("A{$row}", $item[0]);
            $sheet->setCellValue("B{$row}", $item[1]);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }
        $row++;

        // Security Headers
        $this->writeSectionHeader($sheet, $row, 'Security Headers');
        $row++;
        $secHeaders = [
            ['hsts', 'HSTS (Strict-Transport-Security)'],
            ['x_frame_options', 'X-Frame-Options'],
            ['x_content_type_options', 'X-Content-Type-Options'],
            ['csp', 'Content-Security-Policy'],
        ];
        foreach ($secHeaders as [$key, $label]) {
            $present = ! empty($headers[$key]);
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $present ? 'Present' : 'Missing');
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("B{$row}")->getFont()->setBold(true)->setColor(new Color($present ? self::GREEN_FG : self::RED_FG));
            $row++;
        }
        $row++;

        // Sitemap & Robots
        $this->writeSectionHeader($sheet, $row, 'Sitemap & Robots.txt');
        $row++;
        $sheet->setCellValue("A{$row}", 'Sitemap Found');
        $sheet->setCellValue("B{$row}", ($sitemapData['found'] ?? false) ? 'Yes' : 'No');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        if ($sitemapData['found'] ?? false) {
            $sheet->setCellValue("A{$row}", 'Sitemap URL');
            $sheet->setCellValue("B{$row}", $sitemapData['url'] ?? '');
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
            $sheet->setCellValue("A{$row}", 'URLs in Sitemap');
            $sheet->setCellValue("B{$row}", $audit->sitemap_urls_count ?? $sitemapData['url_count'] ?? 0);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }
        $sheet->setCellValue("A{$row}", 'Robots.txt Found');
        $sheet->setCellValue("B{$row}", ($robots['exists'] ?? false) ? 'Yes' : 'No');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        if ($robots['exists'] ?? false) {
            $blocked = ! empty($robots['disallow_rules']) && in_array('/', $robots['disallow_rules']);
            $sheet->setCellValue("A{$row}", 'Allows Crawling');
            $sheet->setCellValue("B{$row}", $blocked ? 'Blocked (Disallow: /)' : 'Yes');
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            if ($blocked) {
                $sheet->getStyle("B{$row}")->getFont()->setColor(new Color(self::RED_FG));
            }
            $row++;
            $sheet->setCellValue("A{$row}", 'Sitemap in Robots.txt');
            $sheet->setCellValue("B{$row}", ! empty($robots['sitemap_urls']) ? 'Yes' : 'Missing');
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
            $sheet->setCellValue("A{$row}", 'Disallow Rules');
            $sheet->setCellValue("B{$row}", count($robots['disallow_rules'] ?? []));
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }
        $row++;

        // Internal Linking
        $pages200 = $audit->pages()->where('status_code', 200);
        $totalPages = $pages200->count();
        if ($totalPages > 0) {
            $this->writeSectionHeader($sheet, $row, 'Internal Linking');
            $row++;
            $linkStats = [
                ['Avg Internal Links / Page', round((float) $pages200->avg('internal_link_count'), 1)],
                ['Orphan Pages (0 inbound)', $pages200->clone()->where('inbound_internal_links', 0)->where('depth', '>', 0)->count()],
                ['Deep Pages (depth > 3)', $pages200->clone()->where('depth', '>', 3)->count()],
                ['Total Pages (200 OK)', $totalPages],
            ];
            foreach ($linkStats as $item) {
                $sheet->setCellValue("A{$row}", $item[0]);
                $sheet->setCellValue("B{$row}", $item[1]);
                $sheet->getStyle("A{$row}")->getFont()->setBold(true);
                $row++;
            }
        }

        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(50);
    }

    private function buildIssuesSheet(Spreadsheet $spreadsheet, SeoAudit $audit): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Issues');

        $headers = ['Severity', 'Category', 'Title', 'Description', 'URL', 'Recommendation'];
        $this->writeHeaderRow($sheet, $headers);

        $issues = $audit->issues()
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->get();

        $row = 2;
        foreach ($issues as $issue) {
            $sheet->setCellValue("A{$row}", ucfirst($issue->severity->value));
            $sheet->setCellValue("B{$row}", $issue->category->label());
            $sheet->setCellValue("C{$row}", $issue->title);
            $sheet->setCellValue("D{$row}", $issue->description);
            $sheet->setCellValue("E{$row}", $issue->url);
            $sheet->setCellValue("F{$row}", $issue->recommendation);

            $bg = match ($issue->severity->value) {
                'critical' => self::CRITICAL_BG,
                'high' => self::HIGH_BG,
                'medium' => self::MEDIUM_BG,
                'low' => self::LOW_BG,
                default => null,
            };
            if ($bg) {
                $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bg);
            }

            $row++;
        }

        $this->autoSizeColumns($sheet, 'A', 'F');
        $sheet->setAutoFilter("A1:F{$row}");
    }

    private function buildPagesSheet(Spreadsheet $spreadsheet, SeoAudit $audit): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Pages');

        $headers = [
            'URL', 'Status', 'Title', 'Title Length', 'Meta Description', 'Desc Length',
            'H1 Tags', 'Word Count', 'Image Count', 'Images w/o Alt',
            'Internal Links', 'External Links', 'Inbound Links',
            'Indexable', 'In Sitemap', 'Blocked by Robots', 'Meta Robots',
            'Canonical URL', 'Self-Canonical',
            'TTFB (ms)', 'Page Size (KB)', 'Depth',
            'Structured Data', 'Has Viewport', 'OG Tags',
            'Redirect Target', 'Redirect Chain',
        ];
        $this->writeHeaderRow($sheet, $headers);

        $pages = $audit->pages()->orderBy('status_code')->get();
        $row = 2;

        foreach ($pages as $p) {
            $col = 1;
            $sheet->setCellValue([$col++, $row], $p->url);
            $sheet->setCellValue([$col++, $row], $p->status_code);
            $sheet->setCellValue([$col++, $row], $p->title);
            $sheet->setCellValue([$col++, $row], $p->title_length);
            $sheet->setCellValue([$col++, $row], $p->meta_description);
            $sheet->setCellValue([$col++, $row], $p->meta_description_length);
            $sheet->setCellValue([$col++, $row], ! empty($p->h1_tags) ? implode(' | ', $p->h1_tags) : '');
            $sheet->setCellValue([$col++, $row], $p->word_count);
            $sheet->setCellValue([$col++, $row], $p->image_count);
            $sheet->setCellValue([$col++, $row], $p->images_without_alt);
            $sheet->setCellValue([$col++, $row], $p->internal_link_count);
            $sheet->setCellValue([$col++, $row], $p->external_link_count);
            $sheet->setCellValue([$col++, $row], $p->inbound_internal_links);
            $sheet->setCellValue([$col++, $row], $p->is_indexable ? 'Yes' : 'No');
            $sheet->setCellValue([$col++, $row], $p->in_sitemap ? 'Yes' : 'No');
            $sheet->setCellValue([$col++, $row], $p->blocked_by_robots ? 'Yes' : 'No');
            $sheet->setCellValue([$col++, $row], $p->meta_robots);
            $sheet->setCellValue([$col++, $row], $p->canonical_url);
            $sheet->setCellValue([$col++, $row], $p->is_self_canonical ? 'Yes' : ($p->canonical_url ? 'No' : ''));
            $sheet->setCellValue([$col++, $row], $p->ttfb_seconds ? round($p->ttfb_seconds * 1000) : null);
            $sheet->setCellValue([$col++, $row], $p->page_size_bytes ? round($p->page_size_bytes / 1024, 1) : null);
            $sheet->setCellValue([$col++, $row], $p->depth);
            $sheet->setCellValue([$col++, $row], ! empty($p->structured_data_types) ? implode(', ', $p->structured_data_types) : '');
            $sheet->setCellValue([$col++, $row], $p->has_viewport_meta ? 'Yes' : 'No');
            $sheet->setCellValue([$col++, $row], ! empty($p->og_tags) ? implode(', ', array_keys($p->og_tags)) : '');
            $sheet->setCellValue([$col++, $row], $p->redirect_target);
            $sheet->setCellValue([$col++, $row], $p->redirect_chain_length);

            // Color coding
            if ($p->status_code && $p->status_code >= 400) {
                $sheet->getStyle("B{$row}")->getFont()->setColor(new Color(self::RED_FG));
            }
            if ($p->title_length && ($p->title_length < 30 || $p->title_length > 60)) {
                $sheet->getStyle("D{$row}")->getFont()->setColor(new Color(self::RED_FG));
            }
            if ($p->word_count !== null && $p->word_count < 300) {
                $sheet->getStyle("H{$row}")->getFont()->setColor(new Color(self::YELLOW_FG));
            }
            if ($p->images_without_alt > 0) {
                $sheet->getStyle("J{$row}")->getFont()->setColor(new Color(self::YELLOW_FG));
            }

            $row++;
        }

        $lastCol = $this->colLetter(count($headers));
        $this->autoSizeColumns($sheet, 'A', $lastCol);
        $sheet->setAutoFilter("A1:{$lastCol}{$row}");
    }

    private function buildBrokenLinksSheet(Spreadsheet $spreadsheet, SeoAudit $audit): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Broken Links');

        $headers = ['Broken URL', 'Status Code', 'Type', 'Found On Page', 'Anchor Text'];
        $this->writeHeaderRow($sheet, $headers);

        $brokenLinks = $audit->links()->broken()->with('page')->get();
        $row = 2;

        foreach ($brokenLinks as $link) {
            $sheet->setCellValue("A{$row}", $link->target_url);
            $sheet->setCellValue("B{$row}", $link->status_code ?? 'Error');
            $sheet->setCellValue("C{$row}", ucfirst($link->type));
            $sheet->setCellValue("D{$row}", $link->page?->url);
            $sheet->setCellValue("E{$row}", $link->anchor_text);

            if ($link->type === 'internal') {
                $sheet->getStyle("C{$row}")->getFont()->setColor(new Color(self::RED_FG));
            }
            $row++;
        }

        $this->autoSizeColumns($sheet, 'A', 'E');
        if ($row > 2) {
            $sheet->setAutoFilter("A1:E{$row}");
        }
    }

    private function buildBrokenImagesSheet(Spreadsheet $spreadsheet, SeoAudit $audit): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Broken Images');

        $headers = ['Broken Image URL', 'Status Code', 'Alt Text', 'Found On Page', 'Content Type', 'File Size (KB)'];
        $this->writeHeaderRow($sheet, $headers);

        $brokenImages = $audit->images()->where('is_broken', true)->with('page')->get();
        $row = 2;

        foreach ($brokenImages as $img) {
            $sheet->setCellValue("A{$row}", $img->image_url);
            $sheet->setCellValue("B{$row}", $img->status_code ?? 'Error');
            $sheet->setCellValue("C{$row}", $img->alt_text ?? '');
            $sheet->setCellValue("D{$row}", $img->page?->url);
            $sheet->setCellValue("E{$row}", $img->content_type);
            $sheet->setCellValue("F{$row}", $img->file_size_bytes ? round($img->file_size_bytes / 1024, 1) : '');
            $row++;
        }

        $this->autoSizeColumns($sheet, 'A', 'F');
        if ($row > 2) {
            $sheet->setAutoFilter("A1:F{$row}");
        }
    }

    private function buildRedirectsSheet(Spreadsheet $spreadsheet, SeoAudit $audit): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Redirects');

        $headers = ['Source URL', 'Status Code', 'Redirect Target', 'Chain Length', 'Depth'];
        $this->writeHeaderRow($sheet, $headers);

        $redirectPages = $audit->pages()
            ->whereNotNull('redirect_target')
            ->orderByDesc('redirect_chain_length')
            ->get();
        $row = 2;

        foreach ($redirectPages as $p) {
            $sheet->setCellValue("A{$row}", $p->url);
            $sheet->setCellValue("B{$row}", $p->status_code);
            $sheet->setCellValue("C{$row}", $p->redirect_target);
            $sheet->setCellValue("D{$row}", $p->redirect_chain_length);
            $sheet->setCellValue("E{$row}", $p->depth);

            if ($p->redirect_chain_length > 2) {
                $sheet->getStyle("D{$row}")->getFont()->setColor(new Color(self::RED_FG));
            }
            $row++;
        }

        $this->autoSizeColumns($sheet, 'A', 'E');
        if ($row > 2) {
            $sheet->setAutoFilter("A1:E{$row}");
        }
    }

    private function buildImagesOverviewSheet(Spreadsheet $spreadsheet, SeoAudit $audit): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Images');

        $headers = ['Image URL', 'Status', 'Has Alt', 'Alt Text', 'Lazy Loading', 'Content Type', 'File Size (KB)', 'Found On Page'];
        $this->writeHeaderRow($sheet, $headers);

        $images = $audit->images()->with('page')->limit(5000)->get();
        $row = 2;

        foreach ($images as $img) {
            $sheet->setCellValue("A{$row}", $img->image_url);
            $sheet->setCellValue("B{$row}", $img->is_broken ? ($img->status_code ?? 'Error') : ($img->status_code ?? 'OK'));
            $sheet->setCellValue("C{$row}", $img->has_alt ? 'Yes' : 'No');
            $sheet->setCellValue("D{$row}", $img->alt_text ?? '');
            $sheet->setCellValue("E{$row}", $img->has_lazy_loading ? 'Yes' : 'No');
            $sheet->setCellValue("F{$row}", $img->content_type);
            $sheet->setCellValue("G{$row}", $img->file_size_bytes ? round($img->file_size_bytes / 1024, 1) : '');
            $sheet->setCellValue("H{$row}", $img->page?->url);

            if ($img->is_broken) {
                $sheet->getStyle("B{$row}")->getFont()->setColor(new Color(self::RED_FG));
            }
            if (! $img->has_alt) {
                $sheet->getStyle("C{$row}")->getFont()->setColor(new Color(self::YELLOW_FG));
            }
            $row++;
        }

        $this->autoSizeColumns($sheet, 'A', 'H');
        if ($row > 2) {
            $sheet->setAutoFilter("A1:H{$row}");
        }
    }

    private function buildLinksMapSheet(Spreadsheet $spreadsheet, SeoAudit $audit): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Links Map');

        $headers = ['Source Page', 'Target URL', 'Type', 'Anchor Text', 'Rel', 'Status', 'Broken'];
        $this->writeHeaderRow($sheet, $headers);

        $links = $audit->links()->with('page')->limit(5000)->get();
        $row = 2;

        foreach ($links as $link) {
            $sheet->setCellValue("A{$row}", $link->page?->url);
            $sheet->setCellValue("B{$row}", $link->target_url);
            $sheet->setCellValue("C{$row}", ucfirst($link->type));
            $sheet->setCellValue("D{$row}", $link->anchor_text);
            $sheet->setCellValue("E{$row}", $link->rel);
            $sheet->setCellValue("F{$row}", $link->status_code);
            $sheet->setCellValue("G{$row}", $link->is_broken ? 'Yes' : 'No');

            if ($link->is_broken) {
                $sheet->getStyle("G{$row}")->getFont()->setColor(new Color(self::RED_FG));
            }
            $row++;
        }

        $this->autoSizeColumns($sheet, 'A', 'G');
        if ($row > 2) {
            $sheet->setAutoFilter("A1:G{$row}");
        }
    }

    private function buildInfrastructureSheet(Spreadsheet $spreadsheet, SeoAudit $audit): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Infrastructure');

        $headers = $audit->security_headers ?? [];
        $ssl = $audit->ssl_info ?? [];
        $robots = $audit->robots_txt_data ?? [];
        $sitemapData = $audit->data['sitemap'] ?? [];
        $redirect = $audit->redirect_info ?? $audit->data['redirects'] ?? [];

        $row = 1;

        // Security Headers
        $this->writeSectionHeader($sheet, $row, 'Security Headers');
        $row++;
        $secHeaders = [
            ['hsts', 'HSTS (Strict-Transport-Security)', 'Add header: Strict-Transport-Security: max-age=31536000; includeSubDomains'],
            ['x_frame_options', 'X-Frame-Options', 'Add header: X-Frame-Options: SAMEORIGIN'],
            ['x_content_type_options', 'X-Content-Type-Options', 'Add header: X-Content-Type-Options: nosniff'],
            ['csp', 'Content-Security-Policy', "Add header: Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'"],
        ];
        $sheet->setCellValue("A{$row}", 'Header');
        $sheet->setCellValue("B{$row}", 'Status');
        $sheet->setCellValue("C{$row}", 'Remediation');
        $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
        $row++;
        foreach ($secHeaders as [$key, $label, $fix]) {
            $present = ! empty($headers[$key]);
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $present ? 'Present' : 'Missing');
            $sheet->setCellValue("C{$row}", $present ? '' : $fix);
            $sheet->getStyle("B{$row}")->getFont()->setBold(true)->setColor(new Color($present ? self::GREEN_FG : self::RED_FG));
            $row++;
        }
        $row++;

        // SSL
        $this->writeSectionHeader($sheet, $row, 'SSL Certificate');
        $row++;
        if (! empty($ssl)) {
            $sslRows = [
                ['Valid', ($ssl['valid'] ?? false) ? 'Yes' : 'No'],
                ['Expiry Date', $ssl['expiry'] ?? 'N/A'],
                ['Days Until Expiry', $ssl['days_until_expiry'] ?? 'N/A'],
                ['Issuer', $ssl['issuer'] ?? 'N/A'],
            ];
            foreach ($sslRows as $item) {
                $sheet->setCellValue("A{$row}", $item[0]);
                $sheet->setCellValue("B{$row}", $item[1]);
                $sheet->getStyle("A{$row}")->getFont()->setBold(true);
                $row++;
            }
        } else {
            $sheet->setCellValue("A{$row}", 'SSL information not available.');
            $row++;
        }
        $row++;

        // Sitemap
        $this->writeSectionHeader($sheet, $row, 'Sitemap');
        $row++;
        $sheet->setCellValue("A{$row}", 'Found');
        $sheet->setCellValue("B{$row}", ($sitemapData['found'] ?? false) ? 'Yes' : 'No');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        if ($sitemapData['found'] ?? false) {
            $sheet->setCellValue("A{$row}", 'URL');
            $sheet->setCellValue("B{$row}", $sitemapData['url'] ?? '');
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
            $sheet->setCellValue("A{$row}", 'URLs Count');
            $sheet->setCellValue("B{$row}", $audit->sitemap_urls_count ?? $sitemapData['url_count'] ?? 0);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }
        $row++;

        // Robots.txt
        $this->writeSectionHeader($sheet, $row, 'Robots.txt');
        $row++;
        $sheet->setCellValue("A{$row}", 'Found');
        $sheet->setCellValue("B{$row}", ($robots['exists'] ?? false) ? 'Yes' : 'No');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        if ($robots['exists'] ?? false) {
            foreach ($robots['disallow_rules'] ?? [] as $rule) {
                $sheet->setCellValue("A{$row}", 'Disallow');
                $sheet->setCellValue("B{$row}", $rule);
                $row++;
            }
        }
        $row++;

        // Redirect chain
        if (! empty($redirect['chain'])) {
            $this->writeSectionHeader($sheet, $row, 'Homepage Redirect Chain');
            $row++;
            foreach ($redirect['chain'] as $step) {
                $sheet->setCellValue("A{$row}", $step['status'] ?? '');
                $sheet->setCellValue("B{$row}", $step['url'] ?? '');
                $row++;
            }
            if ($redirect['has_mixed_ssl'] ?? false) {
                $sheet->setCellValue("A{$row}", 'Warning');
                $sheet->setCellValue("B{$row}", 'Mixed HTTP/HTTPS detected in redirect chain');
                $sheet->getStyle("B{$row}")->getFont()->setColor(new Color(self::RED_FG));
                $row++;
            }
        }

        $sheet->getColumnDimension('A')->setWidth(35);
        $sheet->getColumnDimension('B')->setWidth(50);
        $sheet->getColumnDimension('C')->setWidth(70);
    }

    private function writeSectionHeader(Worksheet $sheet, int $row, string $title): void
    {
        $sheet->setCellValue("A{$row}", $title);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle("A{$row}:C{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::SECTION_BG);
    }

    private function writeHeaderRow(Worksheet $sheet, array $headers): void
    {
        foreach ($headers as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }

        $lastCol = $this->colLetter(count($headers));
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => self::HEADER_FG]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $sheet->freezePane('A2');
    }

    private function autoSizeColumns(Worksheet $sheet, string $from, string $to): void
    {
        for ($col = $from; $col <= $to; $col++) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function colLetter(int $num): string
    {
        $letter = '';
        while ($num > 0) {
            $num--;
            $letter = chr(65 + ($num % 26)) . $letter;
            $num = intdiv($num, 26);
        }

        return $letter;
    }
}
