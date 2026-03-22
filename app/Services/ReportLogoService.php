<?php

namespace App\Services;

use App\Models\ReportTemplate;
use App\Models\Site;

class ReportLogoService
{
    public function __construct(
        protected Site $site,
        protected ReportTemplate $template,
    ) {}

    public function resolveLogoPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $fullPath = storage_path('app/'.$path);
        if (file_exists($fullPath)) {
            return $fullPath;
        }

        if (file_exists($path)) {
            return $path;
        }

        $publicPath = public_path($path);
        if (file_exists($publicPath)) {
            return $publicPath;
        }

        return null;
    }

    public function resolveCompanyLogoFullPath(): ?string
    {
        if ($this->template->company_logo_path) {
            $resolved = $this->resolveLogoPath($this->template->company_logo_path);
            if ($resolved) {
                return $resolved;
            }
        }

        $settingsService = app(SettingsService::class);
        $logoPath = $settingsService->get('branding.logo');

        if (! $logoPath) {
            return null;
        }

        $fullPath = storage_path('app/public/'.$logoPath);

        return file_exists($fullPath) ? $fullPath : null;
    }

    public function getOriginalLogoAsBase64(): ?string
    {
        $fullPath = $this->resolveCompanyLogoFullPath();
        if (! $fullPath) {
            return null;
        }

        $contents = file_get_contents($fullPath);

        if (str_ends_with(strtolower($fullPath), '.svg')) {
            return 'data:image/svg+xml;base64,'.base64_encode($contents);
        }

        $mime = mime_content_type($fullPath) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    public function getLogoAsBase64(): ?string
    {
        $fullPath = $this->resolveCompanyLogoFullPath();
        if (! $fullPath) {
            return null;
        }

        $contents = file_get_contents($fullPath);

        if (str_ends_with(strtolower($fullPath), '.svg')) {
            $contents = preg_replace('/fill:\s*#[0-9a-fA-F]{3,6}/', 'fill: #ffffff', $contents);
            $contents = preg_replace('/fill:\s*url\([^)]+\)/', 'fill: #ffffff', $contents);
            $contents = preg_replace('/fill="#[0-9a-fA-F]{3,6}"/', 'fill="#ffffff"', $contents);

            return 'data:image/svg+xml;base64,'.base64_encode($contents);
        }

        $mime = mime_content_type($fullPath) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    public function resolveClientLogo(): ?string
    {
        $client = $this->site->client;
        if ($client && $client->logo_path) {
            $resolved = $this->resolveLogoPath($client->logo_path);
            if ($resolved) {
                return $resolved;
            }
        }

        return null;
    }

    public function getClientLogoAsBase64(): ?string
    {
        $client = $this->site->client;
        if (! $client || ! $client->logo_path) {
            return null;
        }

        $fullPath = storage_path('app/public/'.$client->logo_path);
        if (! file_exists($fullPath)) {
            $fullPath = $this->resolveLogoPath($client->logo_path);
        }

        if (! $fullPath || ! file_exists($fullPath)) {
            return null;
        }

        $contents = file_get_contents($fullPath);
        if (str_ends_with(strtolower($fullPath), '.svg')) {
            return 'data:image/svg+xml;base64,'.base64_encode($contents);
        }

        $mime = mime_content_type($fullPath) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }
}
