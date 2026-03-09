<?php

namespace App\Services;

use GuzzleHttp\Client;
use Gotenberg\Gotenberg;
use Gotenberg\Stream;
use Illuminate\Support\Facades\Log;

class GotenbergService
{
    protected string $baseUrl;
    protected Client $httpClient;

    public function __construct()
    {
        $this->baseUrl = config('services.gotenberg.url', 'http://gotenberg:3000');
        $this->httpClient = new Client(['timeout' => 120]);
    }

    /**
     * Convert separate cover + body + closing HTML into a single merged PDF.
     */
    public function htmlToPdf(string $coverHtml, string $bodyHtml, ?string $closingHtml = null, ?string $bodyFooterHtml = null, ?string $bodyHeaderHtml = null): string
    {
        // Cover: no margins (full bleed), no header/footer
        $coverPdf = $this->render($coverHtml, null, null, [
            'margins' => ['0mm', '0mm', '0mm', '0mm'],
        ]);

        // Body: no native header (replaced by inline section-top-bar), footer with page numbering
        // Sides=0 so section-top-bar bleeds full-width; CSS padding handles inner margins
        $bodyPdf = $this->render($bodyHtml, null, $bodyFooterHtml, [
            'margins' => ['14mm', '12mm', '14mm', '12mm'],
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

        try {
            $response = Gotenberg::send($request, $this->httpClient);

            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            Log::error('Gotenberg PDF render failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function mergePdfs(string ...$pdfs): string
    {
        $streams = [];
        foreach ($pdfs as $i => $pdf) {
            $streams[] = Stream::string("doc{$i}.pdf", $pdf);
        }

        $request = Gotenberg::pdfEngines($this->baseUrl)
            ->merge(...$streams);

        try {
            $response = Gotenberg::send($request, $this->httpClient);

            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            Log::error('Gotenberg PDF merge failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'pdf_count' => count($pdfs),
            ]);
            throw $e;
        }
    }
}
