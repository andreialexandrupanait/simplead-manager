<?php

namespace App\Services;

use Gotenberg\Gotenberg;
use Gotenberg\Stream;

class GotenbergService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.gotenberg.url', 'http://gotenberg:3000');
    }

    /**
     * Convert separate cover + body + closing HTML into a single merged PDF.
     *
     * Cover & closing are rendered full-bleed with no header/footer.
     * Body is rendered with an optional Gotenberg footer for page numbers.
     */
    public function htmlToPdf(string $coverHtml, string $bodyHtml, ?string $closingHtml = null, ?string $footerHtml = null): string
    {
        // Cover: no margins (full bleed), no header/footer
        $coverPdf = $this->render($coverHtml, null, null, [
            'margins' => ['0mm', '0mm', '0mm', '0mm'],
        ]);

        // Body: 0mm top (header is in-flow), 12mm bottom for footer
        $bodyPdf = $this->render($bodyHtml, null, $footerHtml, [
            'margins' => ['0mm', '12mm', '0mm', '0mm'],
        ]);

        // Closing: no margins (full bleed), no header/footer
        if ($closingHtml) {
            $closingPdf = $this->render($closingHtml, null, null, [
                'margins' => ['0mm', '0mm', '0mm', '0mm'],
            ]);

            return $this->mergePdfs($coverPdf, $bodyPdf, $closingPdf);
        }

        return $this->mergePdfs($coverPdf, $bodyPdf);
    }

    protected function render(string $html, ?string $headerHtml = null, ?string $footerHtml = null, array $options = []): string
    {
        $margins = $options['margins'] ?? ['0mm', '12mm', '0mm', '0mm'];

        $chromium = Gotenberg::chromium($this->baseUrl)->pdf()
            ->paperSize(8.27, 11.7)
            ->margins($margins[0], $margins[1], $margins[2], $margins[3])
            ->printBackground();

        if (isset($options['pageRanges'])) {
            $chromium->nativePageRanges($options['pageRanges']);
        }

        if ($headerHtml) {
            $chromium->header(Stream::string('header.html', $headerHtml));
        }

        if ($footerHtml) {
            $chromium->footer(Stream::string('footer.html', $footerHtml));
        }

        $request = $chromium->html(Stream::string('index.html', $html));
        $response = Gotenberg::send($request);

        return $response->getBody()->getContents();
    }

    protected function mergePdfs(string ...$pdfs): string
    {
        $streams = [];
        foreach ($pdfs as $i => $pdf) {
            $streams[] = Stream::string("doc{$i}.pdf", $pdf);
        }

        $request = Gotenberg::pdfEngines($this->baseUrl)
            ->merge(...$streams);

        $response = Gotenberg::send($request);

        return $response->getBody()->getContents();
    }
}
