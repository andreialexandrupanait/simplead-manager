<?php

namespace App\Http\Controllers;

use App\Models\StorageDestination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class DropboxAuthController extends Controller
{
    public function redirect()
    {
        $appKey = config('services.dropbox.app_key');

        if (empty($appKey)) {
            return redirect()->route('settings.general')
                ->with('error', 'Dropbox App Key is not configured. Set DROPBOX_APP_KEY in your .env file.');
        }

        $params = http_build_query([
            'client_id' => $appKey,
            'response_type' => 'code',
            'redirect_uri' => route('dropbox.callback'),
            'token_access_type' => 'offline',
            'state' => csrf_token(),
            'scope' => 'account_info.read files.metadata.read files.content.read files.content.write',
        ]);

        return redirect("https://www.dropbox.com/oauth2/authorize?{$params}");
    }

    public function callback(Request $request)
    {
        if ($request->has('error')) {
            return redirect()->route('settings.general')
                ->with('error', 'Dropbox authorization was denied: ' . $request->input('error_description', 'Unknown error'));
        }

        $code = $request->input('code');
        $appKey = config('services.dropbox.app_key');
        $appSecret = config('services.dropbox.app_secret');

        // Exchange code for tokens
        $response = Http::asForm()->post('https://api.dropbox.com/oauth2/token', [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => $appKey,
            'client_secret' => $appSecret,
            'redirect_uri' => route('dropbox.callback'),
        ]);

        if ($response->failed()) {
            return redirect()->route('settings.general')
                ->with('error', 'Failed to exchange Dropbox authorization code for tokens.');
        }

        $data = $response->json();

        // Get account info for the name
        $teamMemberId = $data['team_member_id'] ?? null;
        $accountRequest = Http::withToken($data['access_token']);
        if ($teamMemberId) {
            $accountRequest = $accountRequest->withHeaders([
                'Dropbox-API-Select-User' => $teamMemberId,
            ]);
        }
        $accountResponse = $accountRequest
            ->withBody('null', 'application/json')
            ->post('https://api.dropboxapi.com/2/users/get_current_account');

        $accountName = 'Dropbox';
        $rootNamespaceId = null;
        if ($accountResponse->ok()) {
            $account = $accountResponse->json();
            $accountName = 'Dropbox — ' . ($account['email'] ?? $account['name']['display_name'] ?? 'Connected');

            // For Dropbox Business: store root namespace ID so the driver can route
            // API calls to the team namespace (making team folders visible).
            // With a user-level token the root_info .tag is "user" (not "team"),
            // but root_namespace_id still points to the team root when they differ.
            $rootInfo = $account['root_info'] ?? [];
            $rootNs = $rootInfo['root_namespace_id'] ?? null;
            $homeNs = $rootInfo['home_namespace_id'] ?? null;
            if ($rootNs && $homeNs && $rootNs !== $homeNs) {
                $rootNamespaceId = $rootNs;
            }
        }

        // Preserve existing base_path if the destination already exists
        $existing = StorageDestination::where('type', 'dropbox')->first();
        $basePath = $existing?->config['base_path'] ?? '/#1 SAD Workspace/4. Backup';

        $config = [
            'access_token' => encrypt($data['access_token']),
            'refresh_token' => encrypt($data['refresh_token']),
            'app_key' => encrypt($appKey),
            'app_secret' => encrypt($appSecret),
            'base_path' => $basePath,
        ];

        if ($rootNamespaceId) {
            $config['root_namespace_id'] = $rootNamespaceId;
        }

        // For Dropbox Business: store team_member_id so API calls can target this user's files
        if (!empty($data['team_member_id'])) {
            $config['team_member_id'] = $data['team_member_id'];
        }

        // Create or update the storage destination
        $destination = StorageDestination::updateOrCreate(
            ['type' => 'dropbox'],
            [
                'name' => $accountName,
                'is_active' => true,
                'config' => $config,
            ]
        );

        // Get space usage
        $spaceRequest = Http::withToken($data['access_token']);
        if ($teamMemberId) {
            $spaceRequest = $spaceRequest->withHeaders([
                'Dropbox-API-Select-User' => $teamMemberId,
            ]);
        }
        $spaceResponse = $spaceRequest
            ->withBody('null', 'application/json')
            ->post('https://api.dropboxapi.com/2/users/get_space_usage');

        if ($spaceResponse->ok()) {
            $space = $spaceResponse->json();
            $destination->update([
                'used_bytes' => $space['used'] ?? 0,
                'quota_bytes' => $space['allocation']['allocated'] ?? null,
            ]);
        }

        return redirect()->route('settings.general')
            ->with('settings-saved', 'Dropbox storage connected successfully.');
    }
}
