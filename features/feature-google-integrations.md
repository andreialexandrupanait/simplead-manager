# SimpleAd Manager — Feature Spec: Google Analytics & Search Console

---

## Overview

Integrate Google Analytics 4 (GA4) and Google Search Console to display traffic, engagement, search performance, and keyword data for each managed site. Uses OAuth2 for authentication with a single Google connection that works for both services.

---

## PART 1: GOOGLE OAUTH2 SETUP

### 1.1 Google Cloud Console Setup

1. Create a project at https://console.cloud.google.com
2. Enable APIs:
   - Google Analytics Data API
   - Google Search Console API
3. Create OAuth 2.0 credentials:
   - Application type: Web application
   - Authorized redirect URI: `https://manager.simplead.ro/auth/google/callback`
4. Configure OAuth consent screen (external, production)
5. Request scopes:
   - `https://www.googleapis.com/auth/analytics.readonly`
   - `https://www.googleapis.com/auth/webmasters.readonly`

### 1.2 Config

```php
// config/services.php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_REDIRECT_URI', 'https://manager.simplead.ro/auth/google/callback'),
],
```

```env
GOOGLE_CLIENT_ID=your_client_id
GOOGLE_CLIENT_SECRET=your_client_secret
GOOGLE_REDIRECT_URI=https://manager.simplead.ro/auth/google/callback
```

---

## PART 2: DATABASE SCHEMA

### Migration: `google_connections`

Global Google account connections (one per Google account, can be used by multiple sites):

```php
Schema::create('google_connections', function (Blueprint $table) {
    $table->id();
    
    $table->string('google_id')->unique(); // Google account ID
    $table->string('email');
    $table->string('name')->nullable();
    $table->string('avatar_url')->nullable();
    
    // OAuth tokens (encrypted)
    $table->text('access_token');
    $table->text('refresh_token');
    $table->timestamp('token_expires_at');
    
    // Scopes granted
    $table->json('scopes')->nullable(); // ["analytics.readonly", "webmasters.readonly"]
    
    $table->boolean('is_active')->default(true);
    $table->timestamp('last_used_at')->nullable();
    
    $table->timestamps();
});
```

### Migration: `analytics_connections`

Per-site Analytics connection:

```php
Schema::create('analytics_connections', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    $table->foreignId('google_connection_id')->constrained()->onDelete('cascade');
    
    // GA4 Property
    $table->string('property_id'); // "properties/123456789"
    $table->string('property_name')->nullable();
    
    // Data stream (optional, for filtering)
    $table->string('data_stream_id')->nullable();
    $table->string('data_stream_url')->nullable();
    
    $table->boolean('is_active')->default(true);
    $table->timestamp('last_sync_at')->nullable();
    $table->text('last_error')->nullable();
    
    $table->timestamps();
    
    $table->unique(['site_id']);
});
```

### Migration: `search_console_connections`

Per-site Search Console connection:

```php
Schema::create('search_console_connections', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    $table->foreignId('google_connection_id')->constrained()->onDelete('cascade');
    
    // Search Console Property
    $table->string('property_url'); // "sc-domain:simplead.ro" or "https://simplead.ro/"
    $table->string('property_type')->default('url'); // domain, url
    $table->string('permission_level')->nullable(); // siteOwner, siteFullUser, siteRestrictedUser
    
    $table->boolean('is_active')->default(true);
    $table->timestamp('last_sync_at')->nullable();
    $table->text('last_error')->nullable();
    
    $table->timestamps();
    
    $table->unique(['site_id']);
});
```

### Migration: `analytics_cache`

Cache Analytics data to reduce API calls:

```php
Schema::create('analytics_cache', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    
    $table->string('date_range'); // 7d, 28d, 90d
    $table->date('start_date');
    $table->date('end_date');
    
    $table->json('data'); // cached response
    
    $table->timestamp('fetched_at');
    $table->timestamp('expires_at');
    
    $table->index(['site_id', 'date_range']);
});
```

### Migration: `search_console_cache`

```php
Schema::create('search_console_cache', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    
    $table->string('date_range');
    $table->date('start_date');
    $table->date('end_date');
    $table->string('data_type'); // overview, queries, pages, countries, devices
    
    $table->json('data');
    
    $table->timestamp('fetched_at');
    $table->timestamp('expires_at');
    
    $table->index(['site_id', 'date_range', 'data_type']);
});
```

---

## PART 3: OAUTH FLOW

### Routes

```php
// routes/web.php
Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('google.auth');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
```

### Controller

```php
// app/Http/Controllers/GoogleAuthController.php

class GoogleAuthController extends Controller
{
    public function redirect(Request $request)
    {
        // Store return URL for after OAuth
        session(['google_return_url' => $request->get('return_url', route('settings.integrations'))]);
        
        $params = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', [
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/analytics.readonly',
                'https://www.googleapis.com/auth/webmasters.readonly',
            ]),
            'access_type' => 'offline', // get refresh token
            'prompt' => 'consent', // force consent to always get refresh token
            'state' => csrf_token(),
        ]);

        return redirect("https://accounts.google.com/o/oauth2/v2/auth?{$params}");
    }

    public function callback(Request $request)
    {
        if ($request->has('error')) {
            return redirect(session('google_return_url', route('settings.integrations')))
                ->with('error', 'Google authorization was cancelled');
        }

        $code = $request->get('code');

        // Exchange code for tokens
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if ($response->failed()) {
            return redirect(session('google_return_url', route('settings.integrations')))
                ->with('error', 'Failed to connect to Google');
        }

        $tokens = $response->json();

        // Get user info
        $userInfo = Http::withToken($tokens['access_token'])
            ->get('https://www.googleapis.com/oauth2/v2/userinfo')
            ->json();

        // Create or update Google connection
        $connection = GoogleConnection::updateOrCreate(
            ['google_id' => $userInfo['id']],
            [
                'email' => $userInfo['email'],
                'name' => $userInfo['name'] ?? null,
                'avatar_url' => $userInfo['picture'] ?? null,
                'access_token' => encrypt($tokens['access_token']),
                'refresh_token' => encrypt($tokens['refresh_token'] ?? ''),
                'token_expires_at' => now()->addSeconds($tokens['expires_in']),
                'scopes' => ['analytics.readonly', 'webmasters.readonly'],
                'is_active' => true,
                'last_used_at' => now(),
            ]
        );

        return redirect(session('google_return_url', route('settings.integrations')))
            ->with('success', "Connected Google account: {$userInfo['email']}");
    }
}
```

---

## PART 4: GOOGLE API SERVICE

### Token Refresh

```php
// app/Services/GoogleApiService.php

class GoogleApiService
{
    protected GoogleConnection $connection;
    protected string $accessToken;

    public function __construct(GoogleConnection $connection)
    {
        $this->connection = $connection;
        $this->ensureValidToken();
    }

    protected function ensureValidToken(): void
    {
        if ($this->connection->token_expires_at->isFuture()) {
            $this->accessToken = decrypt($this->connection->access_token);
            return;
        }

        // Refresh token
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => decrypt($this->connection->refresh_token),
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            $this->connection->update(['is_active' => false]);
            throw new \Exception('Failed to refresh Google token');
        }

        $tokens = $response->json();

        $this->connection->update([
            'access_token' => encrypt($tokens['access_token']),
            'token_expires_at' => now()->addSeconds($tokens['expires_in']),
        ]);

        $this->accessToken = $tokens['access_token'];
    }

    protected function api(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->accessToken);
    }
}
```

---

## PART 5: GOOGLE ANALYTICS SERVICE

```php
// app/Services/GoogleAnalyticsService.php

class GoogleAnalyticsService extends GoogleApiService
{
    private string $baseUrl = 'https://analyticsdata.googleapis.com/v1beta';

    /**
     * List available GA4 properties for this Google account
     */
    public function listProperties(): array
    {
        $response = $this->api()->get('https://analyticsadmin.googleapis.com/v1beta/accountSummaries');

        if ($response->failed()) {
            throw new \Exception('Failed to list Analytics properties: ' . $response->body());
        }

        $properties = [];
        foreach ($response->json('accountSummaries', []) as $account) {
            foreach ($account['propertySummaries'] ?? [] as $property) {
                $properties[] = [
                    'property_id' => $property['property'],
                    'property_name' => $property['displayName'],
                    'account_name' => $account['displayName'] ?? '',
                ];
            }
        }

        return $properties;
    }

    /**
     * Get overview metrics (users, sessions, pageviews, bounce rate, avg duration)
     */
    public function getOverview(string $propertyId, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'metrics' => [
                ['name' => 'totalUsers'],
                ['name' => 'newUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews'],
                ['name' => 'bounceRate'],
                ['name' => 'averageSessionDuration'],
                ['name' => 'engagedSessions'],
                ['name' => 'engagementRate'],
            ],
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $row = $response->json('rows.0.metricValues', []);

        return [
            'total_users' => (int) ($row[0]['value'] ?? 0),
            'new_users' => (int) ($row[1]['value'] ?? 0),
            'sessions' => (int) ($row[2]['value'] ?? 0),
            'pageviews' => (int) ($row[3]['value'] ?? 0),
            'bounce_rate' => round((float) ($row[4]['value'] ?? 0) * 100, 2),
            'avg_session_duration' => round((float) ($row[5]['value'] ?? 0), 1),
            'engaged_sessions' => (int) ($row[6]['value'] ?? 0),
            'engagement_rate' => round((float) ($row[7]['value'] ?? 0) * 100, 2),
        ];
    }

    /**
     * Get users over time (for chart)
     */
    public function getUsersOverTime(string $propertyId, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'date'],
            ],
            'metrics' => [
                ['name' => 'totalUsers'],
                ['name' => 'newUsers'],
                ['name' => 'sessions'],
            ],
            'orderBys' => [
                ['dimension' => ['dimensionName' => 'date']],
            ],
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $date = $row['dimensionValues'][0]['value'] ?? '';
            $data[] = [
                'date' => substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2),
                'users' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'new_users' => (int) ($row['metricValues'][1]['value'] ?? 0),
                'sessions' => (int) ($row['metricValues'][2]['value'] ?? 0),
            ];
        }

        return $data;
    }

    /**
     * Get traffic sources (channels)
     */
    public function getTrafficSources(string $propertyId, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'sessionDefaultChannelGroup'],
            ],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'totalUsers'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'sessions'], 'desc' => true],
            ],
            'limit' => 10,
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $data = [];
        $total = 0;
        foreach ($response->json('rows', []) as $row) {
            $sessions = (int) ($row['metricValues'][0]['value'] ?? 0);
            $total += $sessions;
            $data[] = [
                'channel' => $row['dimensionValues'][0]['value'] ?? 'Unknown',
                'sessions' => $sessions,
                'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
            ];
        }

        // Add percentages
        foreach ($data as &$item) {
            $item['percentage'] = $total > 0 ? round(($item['sessions'] / $total) * 100, 1) : 0;
        }

        return $data;
    }

    /**
     * Get top pages
     */
    public function getTopPages(string $propertyId, string $startDate, string $endDate, int $limit = 10): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'pagePath'],
                ['name' => 'pageTitle'],
            ],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'totalUsers'],
                ['name' => 'averageSessionDuration'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'screenPageViews'], 'desc' => true],
            ],
            'limit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'path' => $row['dimensionValues'][0]['value'] ?? '/',
                'title' => $row['dimensionValues'][1]['value'] ?? '',
                'pageviews' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
                'avg_time' => round((float) ($row['metricValues'][2]['value'] ?? 0), 1),
            ];
        }

        return $data;
    }

    /**
     * Get device breakdown
     */
    public function getDevices(string $propertyId, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'deviceCategory'],
            ],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'totalUsers'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'sessions'], 'desc' => true],
            ],
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $data = [];
        $total = 0;
        foreach ($response->json('rows', []) as $row) {
            $sessions = (int) ($row['metricValues'][0]['value'] ?? 0);
            $total += $sessions;
            $data[] = [
                'device' => ucfirst($row['dimensionValues'][0]['value'] ?? 'Unknown'),
                'sessions' => $sessions,
                'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
            ];
        }

        foreach ($data as &$item) {
            $item['percentage'] = $total > 0 ? round(($item['sessions'] / $total) * 100, 1) : 0;
        }

        return $data;
    }

    /**
     * Get top countries
     */
    public function getCountries(string $propertyId, string $startDate, string $endDate, int $limit = 10): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'country'],
            ],
            'metrics' => [
                ['name' => 'totalUsers'],
                ['name' => 'sessions'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'totalUsers'], 'desc' => true],
            ],
            'limit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'country' => $row['dimensionValues'][0]['value'] ?? 'Unknown',
                'users' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'sessions' => (int) ($row['metricValues'][1]['value'] ?? 0),
            ];
        }

        return $data;
    }

    /**
     * Get top cities
     */
    public function getCities(string $propertyId, string $startDate, string $endDate, int $limit = 10): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'city'],
                ['name' => 'country'],
            ],
            'metrics' => [
                ['name' => 'totalUsers'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'totalUsers'], 'desc' => true],
            ],
            'limit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'city' => $row['dimensionValues'][0]['value'] ?? 'Unknown',
                'country' => $row['dimensionValues'][1]['value'] ?? '',
                'users' => (int) ($row['metricValues'][0]['value'] ?? 0),
            ];
        }

        return $data;
    }
}
```

---

## PART 6: GOOGLE SEARCH CONSOLE SERVICE

```php
// app/Services/GoogleSearchConsoleService.php

class GoogleSearchConsoleService extends GoogleApiService
{
    private string $baseUrl = 'https://www.googleapis.com/webmasters/v3';
    private string $searchAnalyticsUrl = 'https://searchconsole.googleapis.com/v1';

    /**
     * List available Search Console properties
     */
    public function listProperties(): array
    {
        $response = $this->api()->get("{$this->baseUrl}/sites");

        if ($response->failed()) {
            throw new \Exception('Failed to list Search Console properties: ' . $response->body());
        }

        $properties = [];
        foreach ($response->json('siteEntry', []) as $site) {
            $properties[] = [
                'site_url' => $site['siteUrl'],
                'permission_level' => $site['permissionLevel'] ?? 'unknown',
            ];
        }

        return $properties;
    }

    /**
     * Get search performance overview (clicks, impressions, CTR, position)
     */
    public function getOverview(string $siteUrl, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->searchAnalyticsUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => [],
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $rows = $response->json('rows', []);
        $row = $rows[0] ?? [];

        return [
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
            'position' => round($row['position'] ?? 0, 1),
        ];
    }

    /**
     * Get performance over time (for chart)
     */
    public function getPerformanceOverTime(string $siteUrl, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->searchAnalyticsUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['date'],
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'date' => $row['keys'][0] ?? '',
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1),
            ];
        }

        return $data;
    }

    /**
     * Get top search queries
     */
    public function getTopQueries(string $siteUrl, string $startDate, string $endDate, int $limit = 20): array
    {
        $response = $this->api()->post("{$this->searchAnalyticsUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['query'],
            'rowLimit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'query' => $row['keys'][0] ?? '',
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1),
            ];
        }

        return $data;
    }

    /**
     * Get top pages
     */
    public function getTopPages(string $siteUrl, string $startDate, string $endDate, int $limit = 20): array
    {
        $response = $this->api()->post("{$this->searchAnalyticsUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['page'],
            'rowLimit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'page' => $row['keys'][0] ?? '',
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1),
            ];
        }

        return $data;
    }

    /**
     * Get countries breakdown
     */
    public function getCountries(string $siteUrl, string $startDate, string $endDate, int $limit = 10): array
    {
        $response = $this->api()->post("{$this->searchAnalyticsUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['country'],
            'rowLimit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'country' => strtoupper($row['keys'][0] ?? ''),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1),
            ];
        }

        return $data;
    }

    /**
     * Get devices breakdown
     */
    public function getDevices(string $siteUrl, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->searchAnalyticsUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['device'],
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $device = $row['keys'][0] ?? '';
            $data[] = [
                'device' => match($device) {
                    'MOBILE' => 'Mobile',
                    'DESKTOP' => 'Desktop',
                    'TABLET' => 'Tablet',
                    default => $device,
                },
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1),
            ];
        }

        return $data;
    }

    private function encodeSiteUrl(string $siteUrl): string
    {
        return urlencode($siteUrl);
    }
}
```

---

## PART 7: DATA FETCHING & CACHING

```php
// app/Jobs/FetchAnalyticsData.php

class FetchAnalyticsData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Site $site,
        public string $dateRange = '28d' // 7d, 28d, 90d
    ) {}

    public function handle(): void
    {
        $connection = $this->site->analyticsConnection;
        if (!$connection || !$connection->is_active) return;

        $google = $connection->googleConnection;
        if (!$google || !$google->is_active) return;

        $service = new GoogleAnalyticsService($google);
        $propertyId = $connection->property_id;

        [$startDate, $endDate] = $this->getDateRange();

        try {
            $data = [
                'overview' => $service->getOverview($propertyId, $startDate, $endDate),
                'users_over_time' => $service->getUsersOverTime($propertyId, $startDate, $endDate),
                'traffic_sources' => $service->getTrafficSources($propertyId, $startDate, $endDate),
                'top_pages' => $service->getTopPages($propertyId, $startDate, $endDate),
                'devices' => $service->getDevices($propertyId, $startDate, $endDate),
                'countries' => $service->getCountries($propertyId, $startDate, $endDate),
                'cities' => $service->getCities($propertyId, $startDate, $endDate),
            ];

            // Cache the data
            AnalyticsCache::updateOrCreate(
                [
                    'site_id' => $this->site->id,
                    'date_range' => $this->dateRange,
                ],
                [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'data' => $data,
                    'fetched_at' => now(),
                    'expires_at' => now()->addHours(6),
                ]
            );

            $connection->update([
                'last_sync_at' => now(),
                'last_error' => null,
            ]);

        } catch (\Exception $e) {
            $connection->update(['last_error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function getDateRange(): array
    {
        $endDate = now()->subDay()->format('Y-m-d'); // yesterday (GA4 data has 1-day delay)

        $startDate = match($this->dateRange) {
            '7d' => now()->subDays(7)->format('Y-m-d'),
            '28d' => now()->subDays(28)->format('Y-m-d'),
            '90d' => now()->subDays(90)->format('Y-m-d'),
            default => now()->subDays(28)->format('Y-m-d'),
        };

        return [$startDate, $endDate];
    }
}
```

```php
// app/Jobs/FetchSearchConsoleData.php

class FetchSearchConsoleData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Site $site,
        public string $dateRange = '28d'
    ) {}

    public function handle(): void
    {
        $connection = $this->site->searchConsoleConnection;
        if (!$connection || !$connection->is_active) return;

        $google = $connection->googleConnection;
        if (!$google || !$google->is_active) return;

        $service = new GoogleSearchConsoleService($google);
        $siteUrl = $connection->property_url;

        [$startDate, $endDate] = $this->getDateRange();

        try {
            // Fetch all data types
            $dataTypes = [
                'overview' => fn() => $service->getOverview($siteUrl, $startDate, $endDate),
                'performance_over_time' => fn() => $service->getPerformanceOverTime($siteUrl, $startDate, $endDate),
                'queries' => fn() => $service->getTopQueries($siteUrl, $startDate, $endDate),
                'pages' => fn() => $service->getTopPages($siteUrl, $startDate, $endDate),
                'countries' => fn() => $service->getCountries($siteUrl, $startDate, $endDate),
                'devices' => fn() => $service->getDevices($siteUrl, $startDate, $endDate),
            ];

            foreach ($dataTypes as $type => $fetcher) {
                $data = $fetcher();

                SearchConsoleCache::updateOrCreate(
                    [
                        'site_id' => $this->site->id,
                        'date_range' => $this->dateRange,
                        'data_type' => $type,
                    ],
                    [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'data' => $data,
                        'fetched_at' => now(),
                        'expires_at' => now()->addHours(6),
                    ]
                );
            }

            $connection->update([
                'last_sync_at' => now(),
                'last_error' => null,
            ]);

        } catch (\Exception $e) {
            $connection->update(['last_error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function getDateRange(): array
    {
        // Search Console data has 2-3 day delay
        $endDate = now()->subDays(3)->format('Y-m-d');

        $startDate = match($this->dateRange) {
            '7d' => now()->subDays(10)->format('Y-m-d'),
            '28d' => now()->subDays(31)->format('Y-m-d'),
            '90d' => now()->subDays(93)->format('Y-m-d'),
            default => now()->subDays(31)->format('Y-m-d'),
        };

        return [$startDate, $endDate];
    }
}
```

### Scheduler

```php
// Refresh Google data daily at 6 AM
Schedule::call(function () {
    Site::whereHas('analyticsConnection', fn($q) => $q->where('is_active', true))
        ->each(function ($site) {
            FetchAnalyticsData::dispatch($site, '28d');
        });

    Site::whereHas('searchConsoleConnection', fn($q) => $q->where('is_active', true))
        ->each(function ($site) {
            FetchSearchConsoleData::dispatch($site, '28d');
        });
})->dailyAt('06:00');
```

---

## PART 8: UI PAGES

### 8.1 Analytics Page — Site Context (`/sites/{site}/analytics`)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Analytics — simplead.ro                    [7d] [28d ●] [90d] [⟳] │
│  Data from Dec 5 - Jan 1, 2026  •  Updated 2 hours ago             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─ Overview ──────────────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐        │ │
│  │  │   1,245  │  │    892   │  │   2,156  │  │   42.3%  │        │ │
│  │  │  Users   │  │ New Users│  │ Sessions │  │Bounce Rate│       │ │
│  │  └──────────┘  └──────────┘  └──────────┘  └──────────┘        │ │
│  │                                                                  │ │
│  │  ┌──────────┐  ┌──────────┐  ┌──────────┐                       │ │
│  │  │   4,521  │  │  2m 34s  │  │   68.5%  │                       │ │
│  │  │ Pageviews│  │ Avg Time │  │Engagement│                        │ │
│  │  └──────────┘  └──────────┘  └──────────┘                       │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Users Over Time ───────────────────────────────────────────────┐ │
│  │  80 ─┬──────────────────────────────────────────────────────    │ │
│  │      │      ╭─╮                                                  │ │
│  │  60 ─┤    ╭─╯ ╰─╮     ╭──╮                                      │ │
│  │      │  ╭─╯     ╰─────╯  ╰─────╮                                │ │
│  │  40 ─┤╭─╯                      ╰─────╮   ╭────╮                 │ │
│  │      ││                              ╰───╯    ╰──               │ │
│  │  20 ─┤│                                                          │ │
│  │      ││                                                          │ │
│  │   0 ─┴┴──────────────────────────────────────────────────────   │ │
│  │       Dec 5    Dec 12    Dec 19    Dec 26    Jan 1             │ │
│  │                                                                  │ │
│  │  ── Users   ── New Users   ── Sessions                          │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Traffic Sources ─────────────┐  ┌─ Top Pages ──────────────────┐│
│  │                               │  │                               ││
│  │  Organic Search   650 (52%)   │  │  /                 1,245     ││
│  │  ████████████████████         │  │  /contact/          432      ││
│  │                               │  │  /services/         387      ││
│  │  Direct           380 (30%)   │  │  /blog/post-1       289      ││
│  │  ████████████                 │  │  /about/            234      ││
│  │                               │  │                               ││
│  │  Social            125 (10%)  │  │                               ││
│  │  ████                         │  │                               ││
│  │                               │  │                               ││
│  │  Referral          90 (8%)    │  │                               ││
│  │  ███                          │  │                               ││
│  └───────────────────────────────┘  └───────────────────────────────┘│
│                                                                       │
│  ┌─ Devices ─────────────────────┐  ┌─ Countries ──────────────────┐│
│  │                               │  │                               ││
│  │  📱 Mobile       720 (58%)    │  │  🇷🇴 Romania        890 (71%) ││
│  │  💻 Desktop      480 (38%)    │  │  🇺🇸 United States  156 (13%) ││
│  │  📱 Tablet        45 (4%)     │  │  🇩🇪 Germany         89 (7%)  ││
│  │                               │  │  🇫🇷 France          52 (4%)  ││
│  │                               │  │  🇬🇧 UK              38 (3%)  ││
│  └───────────────────────────────┘  └───────────────────────────────┘│
│                                                                       │
│  Not connected? [Connect Google Analytics]                           │
└─────────────────────────────────────────────────────────────────────┘
```

### 8.2 Search Console Page — Site Context (`/sites/{site}/search-console`)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Search Console — simplead.ro               [7d] [28d ●] [90d] [⟳] │
│  Data from Dec 2 - Dec 30, 2025  •  Updated 2 hours ago            │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─ Overview ──────────────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐        │ │
│  │  │    56    │  │   1.3K   │  │   4.30%  │  │   8.5    │        │ │
│  │  │  Clicks  │  │Impressions│  │   CTR   │  │ Position │        │ │
│  │  └──────────┘  └──────────┘  └──────────┘  └──────────┘        │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Performance Over Time ─────────────────────────────────────────┐ │
│  │  (chart with clicks, impressions, CTR, position lines)          │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Top Search Queries ────────────────────────────────────────────┐ │
│  │  Query                      │ Clicks │ Impr. │ CTR   │ Position│ │
│  │ ───────────────────────────────────────────────────────────────  │ │
│  │  simplead manager            │   12   │  45   │ 26.7% │   1.2   │ │
│  │  wordpress management tool   │    8   │  89   │  9.0% │   4.5   │ │
│  │  site monitoring romania     │    6   │  123  │  4.9% │   6.8   │ │
│  │  backup wordpress automatic  │    5   │  67   │  7.5% │   3.2   │ │
│  │  uptime monitor free         │    4   │  234  │  1.7% │  12.4   │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Top Pages ─────────────────────────────────────────────────────┐ │
│  │  Page                        │ Clicks │ Impr. │ CTR   │ Position│ │
│  │ ───────────────────────────────────────────────────────────────  │ │
│  │  https://simplead.ro/        │   23   │  326  │  7.1% │   8.3   │ │
│  │  /services/wordpress-mana... │    9   │   60  │ 15.0% │   5.1   │ │
│  │  /blog/how-to-backup-word... │    7   │   89  │  7.9% │   7.2   │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Countries ───────────────┐  ┌─ Devices ────────────────────────┐│
│  │                           │  │                                   ││
│  │  🇷🇴 ROU    50   1,033    │  │  📱 Mobile      37    672         ││
│  │  🇺🇸 USA     2     114    │  │  💻 Desktop     19    639         ││
│  │  🇨🇭 CHE     1       5    │  │  📱 Tablet       0      4         ││
│  └───────────────────────────┘  └───────────────────────────────────┘│
│                                                                       │
│  Not connected? [Connect Google Search Console]                      │
└─────────────────────────────────────────────────────────────────────┘
```

### 8.3 Connection Flow

When clicking "Connect Google Analytics" or "Connect Google Search Console":

1. If no Google account connected yet → redirect to Google OAuth
2. After OAuth → show property picker modal:

```
┌─────────────────────────────────────────────────────────────────────┐
│  Select Google Analytics Property                            [✕]   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  Connected as: andrei@simplead.ro                                   │
│                                                                       │
│  Select the GA4 property for simplead.ro:                           │
│                                                                       │
│  ┌─────────────────────────────────────────────────────────────────┐│
│  │  ○ SimpleAd Manager - GA4 (properties/123456789)                ││
│  │    Account: SimpleAd                                            ││
│  ├─────────────────────────────────────────────────────────────────┤│
│  │  ○ Client Site - GA4 (properties/987654321)                     ││
│  │    Account: Client Account                                      ││
│  ├─────────────────────────────────────────────────────────────────┤│
│  │  ○ Test Property - GA4 (properties/111222333)                   ││
│  │    Account: SimpleAd                                            ││
│  └─────────────────────────────────────────────────────────────────┘│
│                                                                       │
│                                        [Cancel]  [Connect Property]  │
└─────────────────────────────────────────────────────────────────────┘
```

Same for Search Console:

```
┌─────────────────────────────────────────────────────────────────────┐
│  Select Search Console Property                              [✕]   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  Select the property for simplead.ro:                               │
│                                                                       │
│  ┌─────────────────────────────────────────────────────────────────┐│
│  │  ○ https://simplead.ro/                          (URL prefix)  ││
│  │    Permission: Owner                                            ││
│  ├─────────────────────────────────────────────────────────────────┤│
│  │  ○ sc-domain:simplead.ro                         (Domain)      ││
│  │    Permission: Full                                             ││
│  └─────────────────────────────────────────────────────────────────┘│
│                                                                       │
│                                        [Cancel]  [Connect Property]  │
└─────────────────────────────────────────────────────────────────────┘
```

### 8.4 Settings — Integrations Page (`/settings/integrations`)

Global Google accounts management:

```
┌─────────────────────────────────────────────────────────────────────┐
│  Integrations                                                        │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─ Google Accounts ───────────────────────────────────────────────┐ │
│  │                                               [+ Add Account]    │ │
│  │                                                                  │ │
│  │  ┌────────────────────────────────────────────────────────────┐ │ │
│  │  │  📧 andrei@simplead.ro                                     │ │ │
│  │  │  Connected: Jan 15, 2026  •  Used by 3 sites              │ │ │
│  │  │  Scopes: Analytics, Search Console                         │ │ │
│  │  │                                           [Disconnect]     │ │ │
│  │  └────────────────────────────────────────────────────────────┘ │ │
│  │                                                                  │ │
│  │  ┌────────────────────────────────────────────────────────────┐ │ │
│  │  │  📧 client@example.com                                     │ │ │
│  │  │  Connected: Feb 1, 2026  •  Used by 1 site                │ │ │
│  │  │  Scopes: Analytics, Search Console                         │ │ │
│  │  │                                           [Disconnect]     │ │ │
│  │  └────────────────────────────────────────────────────────────┘ │ │
│  └──────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
```

---

## PART 9: LIVEWIRE COMPONENTS

```
app/Livewire/
├── Sites/Detail/
│   ├── SiteAnalytics.php              # Analytics page with date range
│   └── SiteSearchConsole.php          # Search Console page
│
├── Settings/
│   └── IntegrationsSettings.php       # Google accounts management
│
├── Components/
│   ├── AnalyticsOverview.php          # Metric cards
│   ├── AnalyticsChart.php             # Users over time chart
│   ├── TrafficSourcesChart.php        # Channel breakdown
│   ├── TopPagesTable.php              # Top pages table
│   ├── DevicesChart.php               # Device breakdown (pie/donut)
│   ├── CountriesTable.php             # Top countries
│   ├── SearchConsoleChart.php         # Performance over time
│   ├── QueriesTable.php               # Top search queries
│   ├── PropertyPickerModal.php        # Select GA/GSC property
│   └── GoogleAccountCard.php          # Account display with disconnect
```

---

## PART 10: IMPLEMENTATION CHECKLIST

### Google OAuth
- [ ] Add Google config to config/services.php and .env
- [ ] Create GoogleAuthController (redirect + callback)
- [ ] Add OAuth routes
- [ ] Test the full OAuth flow
- [ ] Handle token refresh

### Database & Models
- [ ] Create migration: google_connections
- [ ] Create migration: analytics_connections
- [ ] Create migration: search_console_connections
- [ ] Create migration: analytics_cache
- [ ] Create migration: search_console_cache
- [ ] Create models with relationships and casts
- [ ] Add connections to Site model

### API Services
- [ ] Create GoogleApiService (base class with token refresh)
- [ ] Create GoogleAnalyticsService (all GA4 Data API methods)
- [ ] Create GoogleSearchConsoleService (all Search Analytics API methods)

### Data Fetching
- [ ] Create FetchAnalyticsData job
- [ ] Create FetchSearchConsoleData job
- [ ] Add scheduler entry (daily at 06:00)
- [ ] Implement caching strategy (6 hour expiry)

### UI Pages
- [ ] Build SiteAnalytics page (overview cards, users chart, traffic sources, top pages, devices, countries)
- [ ] Build SiteSearchConsole page (overview cards, performance chart, queries table, pages table, countries, devices)
- [ ] Build PropertyPickerModal (list properties, select, save connection)
- [ ] Build IntegrationsSettings page (Google accounts list, add/disconnect)
- [ ] Date range selector (7d, 28d, 90d)
- [ ] Refresh button (triggers manual data fetch)
- [ ] Handle "not connected" state with connect button

### Charts
- [ ] Users over time line chart (use Chart.js or similar)
- [ ] Traffic sources horizontal bar chart
- [ ] Devices pie/donut chart
- [ ] Search Console performance multi-line chart (clicks, impressions)

### Integration
- [ ] Show Analytics summary on site overview (users, sessions from last 28d)
- [ ] Show Search Console summary on site overview (clicks, impressions)
- [ ] These data points will be used in PDF reports (next feature)
