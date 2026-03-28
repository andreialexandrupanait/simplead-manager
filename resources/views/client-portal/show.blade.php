<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $client->name }} — Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="mx-auto max-w-4xl px-4 py-8">
        {{-- Header --}}
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900">{{ $client->name }}</h1>
            <p class="text-sm text-gray-500">Site management reports and status overview</p>
        </div>

        {{-- Site Health Overview --}}
        <div class="mb-8">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Sites</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach($sites as $site)
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="font-medium text-gray-900">{{ $site->name }}</h3>
                                <p class="text-xs text-gray-500">{{ $site->url }}</p>
                            </div>
                            <span class="inline-flex h-3 w-3 rounded-full {{ $site->is_up ? 'bg-green-500' : 'bg-red-500' }}"></span>
                        </div>
                        <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                            <div>
                                <p class="text-xs text-gray-500">Uptime</p>
                                <p class="text-sm font-semibold {{ ($site->uptimeMonitor?->uptime_30d ?? 0) >= 99 ? 'text-green-600' : 'text-yellow-600' }}">
                                    {{ $site->uptimeMonitor ? number_format($site->uptimeMonitor->uptime_30d, 2) . '%' : 'N/A' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Performance</p>
                                <p class="text-sm font-semibold text-gray-900">
                                    {{ $site->performanceMonitor?->latest_mobile_score ?? 'N/A' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Last Backup</p>
                                <p class="text-sm font-semibold text-gray-900">
                                    {{ $site->latestCompletedBackup?->completed_at?->diffForHumans() ?? 'None' }}
                                </p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Reports --}}
        <div>
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Reports</h2>
            @if($reports->isEmpty())
                <div class="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-500">
                    No reports available yet.
                </div>
            @else
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Report</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Site</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Date</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">Download</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($reports as $report)
                                <tr>
                                    <td class="px-4 py-3 text-sm text-gray-900">{{ $report->title }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $report->site?->name }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $report->generated_at?->format('M j, Y') }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('client-portal.download', [$client->portal_token, $report]) }}"
                                           class="text-sm font-medium text-purple-600 hover:text-purple-800">
                                            PDF
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <p class="mt-8 text-center text-xs text-gray-400">Powered by {{ config('app.name') }}</p>
    </div>
</body>
</html>
