<?php

namespace App\Http\Controllers;

use App\Models\StatusPage;
use App\Services\StatusPageService;
use Illuminate\Http\Request;

class StatusPageController extends Controller
{
    public function __invoke(string $slug)
    {
        $statusPage = StatusPage::where('slug', $slug)->firstOrFail();

        if (!$statusPage->is_public) {
            abort(404);
        }

        // Check password protection
        if ($statusPage->password_hash) {
            $authenticated = session("status-page-auth.{$statusPage->id}");
            if (!$authenticated) {
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

    public function api(string $slug)
    {
        $statusPage = StatusPage::where('slug', $slug)->firstOrFail();

        if (!$statusPage->is_public) {
            abort(404);
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

        return back()->withErrors(['password' => 'Incorrect password.']);
    }
}
