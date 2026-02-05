<?php

namespace App\Http\Middleware;

use App\Models\StatusPage;
use App\Services\StatusPageService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StatusPageCustomDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);

        // Skip if request is for the main app domain
        if ($host === $appHost) {
            return $next($request);
        }

        $statusPage = StatusPage::where('custom_domain', $host)
            ->where('is_public', true)
            ->first();

        if (!$statusPage) {
            return $next($request);
        }

        $path = $request->path();

        // Handle password authentication POST
        if ($path === 'auth' && $request->isMethod('post')) {
            $request->validate(['password' => 'required|string']);

            if (StatusPageService::verifyPassword($statusPage, $request->password)) {
                session(["status-page-auth.{$statusPage->id}" => true]);
                return redirect('/');
            }

            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        // Handle JSON API
        if ($path === 'api') {
            $data = StatusPageService::getPublicData($statusPage);

            return response()->json([
                'status' => 'ok',
                'data' => $data,
            ]);
        }

        // Handle root path — show status page
        if ($path === '/' || $path === '') {
            // Check password protection
            if ($statusPage->password_hash) {
                $authenticated = session("status-page-auth.{$statusPage->id}");
                if (!$authenticated) {
                    return response()->view('status-page.password', [
                        'statusPage' => $statusPage,
                    ]);
                }
            }

            $data = StatusPageService::getPublicData($statusPage);

            return response()->view('status-page.show', [
                'statusPage' => $statusPage,
                'data' => $data,
            ]);
        }

        return $next($request);
    }
}
