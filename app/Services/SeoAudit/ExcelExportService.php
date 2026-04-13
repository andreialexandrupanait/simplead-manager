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
        $this->buildLinksMapSheet($spreadsheet, $audit);
        $this->buildSecuritySheet($spreadsheet, $audit);

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

        $data = [
            ['SEO Audit Report'],
            [],
            ['Site', $site?->url ?? 'N/A'],
            ['Site Name', $site?->name ?? 'N/A'],
            ['Scan Date', $audit->scanned_at?->format('M d, Y H:i') ?? 'N/A'],
            ['Duration', $audit->scan_duration ? gmdate('H:i:s', $audit->scan_duration) : 'N/A'],
            ['Pages Crawled', $audit->pages_crawled],
            ['SEO Plugin', $audit->seo_plugin ?? 'None detected'],
            [],
            ['Overall Score', $audit->score],
            ['Technical SEO', $cats['technical'] ?? 'N/A'],
            ['On-Page', $cats['on_page'] ?? 'N/A'],
            ['Performance', $cats['performance'] ?? 'N/A'],
            ['Other', $cats['other'] ?? 'N/A'],
            [],
            ['Issue Summary'],
            ['Critical', $audit->critical_count],
            ['High', $audit->high_count],
            ['Medium', $audit->medium_count],
            ['Low', $audit->low_count],
            ['Info', $audit->info_count],
            ['Total', $audit->totalIssues()],
            [],
            ['SSL Certificate'],
            ['Valid', ($ssl['valid'] ?? false) ? 'Yes' : 'No'],
            ['Expiry', $ssl['expiry'] ?? 'N/A'],
            ['Days Until Expiry', $ssl['days_until_expiry'] ?? 'N/A'],
            ['Issuer', $ssl['issuer'] ?? 'N/A'],
        ];

        foreach ($data as $i => $row) {
            foreach ($row as $j => $val) {
                $sheet->setCellValue([$j + 1, $i + 1], $val);
            }
        }

        // Style title
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A10')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A16')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A24')->getFont()->setBold(true)->setSize(12);

        // Score coloring
        $score = $audit->score;
        $scoreColor = $score >= 80 ? self::GREEN_FG : ($score >= 50 ? self::YELLOW_FG : self::RED_FG);
        $sheet->getStyle('B10')->getFont()->setBold(true)->setSize(18)->setColor(new Color($scoreColor));

        foreach (['B11', 'B12', 'B13', 'B14'] as $cell) {
            $val = $sheet->getCell($cell)->getValue();
            if (is_numeric($val)) {
                $c = $val >= 80 ? self::GREEN_FG : ($val >= 50 ? self::YELLOW_FG : self::RED_FG);
                $sheet->getStyle($cell)->getFont()->setBold(true)->setColor(new Color($c));
            }
        }

        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(40);
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

        $headers = ['URL', 'Status', 'Title', 'Title Length', 'Meta Description', 'Desc Length', 'Word Count', 'Internal Links', 'External Links', 'Indexable', 'Canonical URL', 'TTFB (s)', 'Page Size (KB)', 'Depth'];
        $this->writeHeaderRow($sheet, $headers);

        $pages = $audit->pages()->orderBy('status_code')->get();
        $row = 2;

        foreach ($pages as $p) {
            $sheet->setCellValue("A{$row}", $p->url);
            $sheet->setCellValue("B{$row}", $p->status_code);
            $sheet->setCellValue("C{$row}", $p->title);
            $sheet->setCellValue("D{$row}", $p->title_length);
            $sheet->setCellValue("E{$row}", $p->meta_description);
            $sheet->setCellValue("F{$row}", $p->meta_description_length);
            $sheet->setCellValue("G{$row}", $p->word_count);
            $sheet->setCellValue("H{$row}", $p->internal_link_count);
            $sheet->setCellValue("I{$row}", $p->external_link_count);
            $sheet->setCellValue("J{$row}", $p->is_indexable ? 'Yes' : 'No');
            $sheet->setCellValue("K{$row}", $p->canonical_url);
            $sheet->setCellValue("L{$row}", $p->ttfb_seconds);
            $sheet->setCellValue("M{$row}", $p->page_size_bytes ? round($p->page_size_bytes / 1024, 1) : null);
            $sheet->setCellValue("N{$row}", $p->depth);

            if ($p->status_code && $p->status_code >= 400) {
                $sheet->getStyle("B{$row}")->getFont()->setColor(new Color(self::RED_FG));
            }
            if ($p->title_length && ($p->title_length < 30 || $p->title_length > 60)) {
                $sheet->getStyle("D{$row}")->getFont()->setColor(new Color(self::RED_FG));
            }
            if ($p->word_count !== null && $p->word_count < 300) {
                $sheet->getStyle("G{$row}")->getFont()->setColor(new Color(self::YELLOW_FG));
            }

            $row++;
        }

        $this->autoSizeColumns($sheet, 'A', 'N');
        $sheet->setAutoFilter("A1:N{$row}");
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
            $row++;
        }

        $this->autoSizeColumns($sheet, 'A', 'E');
        if ($row > 2) {
            $sheet->setAutoFilter("A1:E{$row}");
        }
    }

    private function buildLinksMapSheet(Spreadsheet $spreadsheet, SeoAudit $audit): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Links Map');

        $headers = ['Source Page', 'Target URL', 'Type', 'Anchor Text', 'Rel', 'Status'];
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
            $row++;
        }

        $this->autoSizeColumns($sheet, 'A', 'F');
        if ($row > 2) {
            $sheet->setAutoFilter("A1:F{$row}");
        }
    }

    private function buildSecuritySheet(Spreadsheet $spreadsheet, SeoAudit $audit): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Security');

        $headers = $audit->security_headers ?? [];
        $ssl = $audit->ssl_info ?? [];

        $data = [
            ['Security Headers'],
            ['HSTS (Strict-Transport-Security)', ($headers['hsts'] ?? false) ? 'Present' : 'Missing'],
            ['X-Frame-Options', ($headers['x_frame_options'] ?? false) ? 'Present' : 'Missing'],
            ['X-Content-Type-Options', ($headers['x_content_type_options'] ?? false) ? 'Present' : 'Missing'],
            ['Content-Security-Policy', ($headers['csp'] ?? false) ? 'Present' : 'Missing'],
            [],
            ['SSL Certificate'],
            ['Valid', ($ssl['valid'] ?? false) ? 'Yes' : 'No'],
            ['Expiry Date', $ssl['expiry'] ?? 'N/A'],
            ['Days Until Expiry', $ssl['days_until_expiry'] ?? 'N/A'],
            ['Issuer', $ssl['issuer'] ?? 'N/A'],
        ];

        foreach ($data as $i => $row) {
            foreach ($row as $j => $val) {
                $sheet->setCellValue([$j + 1, $i + 1], $val);
            }
        }

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A7')->getFont()->setBold(true)->setSize(14);

        // Color present/missing
        foreach ([2, 3, 4, 5] as $r) {
            $val = $sheet->getCell("B{$r}")->getValue();
            $color = $val === 'Present' ? self::GREEN_FG : self::RED_FG;
            $sheet->getStyle("B{$r}")->getFont()->setBold(true)->setColor(new Color($color));
        }

        $sheet->getColumnDimension('A')->setWidth(35);
        $sheet->getColumnDimension('B')->setWidth(25);
    }

    private function writeHeaderRow(Worksheet $sheet, array $headers): void
    {
        foreach ($headers as $i => $header) {
            $col = chr(65 + $i);
            $sheet->setCellValue("{$col}1", $header);
        }

        $lastCol = chr(64 + count($headers));
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
}
