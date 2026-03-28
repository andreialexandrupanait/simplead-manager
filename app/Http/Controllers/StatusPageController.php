<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StatusPage;
use App\Services\StatusPageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StatusPageController extends Controller
{
    public function __invoke(string $slug)
    {
        $statusPage = StatusPage::where('slug', $slug)->firstOrFail();

        if (! $statusPage->is_public) {
            abort(404);
        }

        // Check password protection
        if ($statusPage->password_hash) {
            $authenticated = session("status-page-auth.{$statusPage->id}");
            if (! $authenticated) {
                return view('status-page.password', [
                    'statusPage' => $statusPage,
                ]);
            }
        }

        $data = StatusPageService::getPublicData($statusPage);

        return view('status-page.show', [
            'statusPage' => $statusPage,
            'data' => $data,
        ]);
    }

    public function api(Request $request, string $slug)
    {
        $statusPage = StatusPage::where('slug', $slug)->firstOrFail();

        if (! $statusPage->is_public) {
            abort(404);
        }

        // Enforce password protection on API endpoint
        if ($statusPage->password_hash) {
            $authenticated = session("status-page-auth.{$statusPage->id}");
            if (! $authenticated) {
                abort(403, 'This status page is password protected.');
            }
        }

        $data = StatusPageService::getPublicData($statusPage);

        return response()->json([
            'status' => 'ok',
            'data' => $data,
        ]);
    }

    public function authenticate(Request $request, string $slug)
    {
        $statusPage = StatusPage::where('slug', $slug)->firstOrFail();

        $request->validate([
            'password' => 'required|string',
        ]);

        if (StatusPageService::verifyPassword($statusPage, $request->password)) {
            session(["status-page-auth.{$statusPage->id}" => true]);

            return redirect()->route('status-page.show', $slug);
        }

        Log::warning('Failed status page auth attempt', [
            'slug' => $slug,
            'ip' => $request->ip(),
        ]);

        return back()->withErrors(['password' => 'Incorrect password.']);
    }

    public function badge(string $slug)
    {
        $statusPage = StatusPage::where('slug', $slug)->where('is_public', true)->firstOrFail();
        $status = $statusPage->overall_status ?? 'operational';

        [$label, $color] = match ($status) {
            'outage' => ['Outage', '#e53e3e'],
            'degraded' => ['Degraded', '#dd6b20'],
            'maintenance' => ['Maintenance', '#3182ce'],
            default => ['Operational', '#38a169'],
        };

        $lw = 50;
        $vw = (int) (strlen($label) * 7.2 + 16);
        $tw = $lw + $vw;
        $vx = $lw + (int) ($vw / 2);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$tw.'" height="20" role="img" aria-label="Status: '.$label.'">'
            .'<linearGradient id="s" x2="0" y2="100%"><stop offset="0" stop-color="#bbb" stop-opacity=".1"/><stop offset="1" stop-opacity=".1"/></linearGradient>'
            .'<clipPath id="r"><rect width="'.$tw.'" height="20" rx="3" fill="#fff"/></clipPath>'
            .'<g clip-path="url(#r)"><rect width="'.$lw.'" height="20" fill="#555"/><rect x="'.$lw.'" width="'.$vw.'" height="20" fill="'.$color.'"/><rect width="'.$tw.'" height="20" fill="url(#s)"/></g>'
            .'<g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" text-rendering="geometricPrecision" font-size="11">'
            .'<text x="25" y="14">status</text><text x="'.$vx.'" y="14">'.$label.'</text></g></svg>';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-cache, max-age=0',
        ]);
    }
}
