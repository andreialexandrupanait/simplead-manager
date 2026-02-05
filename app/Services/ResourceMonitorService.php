<?php

namespace App\Services;

use App\Models\ResourceCheck;
use App\Models\Site;
use Illuminate\Support\Collection;

class ResourceMonitorService
{
    public function fetchAndStore(Site $site): ResourceCheck
    {
        $api = new WordPressApiService($site);

        try {
            $data = $api->getServerResources();

            return ResourceCheck::create([
                'site_id' => $site->id,
                'cpu_usage' => $data['cpu_usage'] ?? null,
                'memory_used' => $data['memory_used'] ?? null,
                'memory_total' => $data['memory_total'] ?? null,
                'memory_percentage' => $data['memory_percentage'] ?? null,
                'disk_used' => $data['disk_used'] ?? null,
                'disk_total' => $data['disk_total'] ?? null,
                'disk_percentage' => $data['disk_percentage'] ?? null,
                'load_average_1' => $data['load_average_1'] ?? null,
                'load_average_5' => $data['load_average_5'] ?? null,
                'load_average_15' => $data['load_average_15'] ?? null,
                'is_available' => $data['is_available'] ?? true,
                'checked_at' => now(),
            ]);
        } catch (\Exception $e) {
            return ResourceCheck::create([
                'site_id' => $site->id,
                'is_available' => false,
                'checked_at' => now(),
            ]);
        }
    }

    public function checkThresholds(ResourceCheck $check): array
    {
        $violations = [];

        if (!$check->is_available) {
            return $violations;
        }

        if ($check->disk_percentage !== null) {
            if ($check->disk_percentage > 90) {
                $violations[] = 'disk_space_critical';
            } elseif ($check->disk_percentage > 80) {
                $violations[] = 'disk_space_warning';
            }
        }

        if ($check->memory_percentage !== null) {
            if ($check->memory_percentage > 90) {
                $violations[] = 'memory_critical';
            } elseif ($check->memory_percentage > 80) {
                $violations[] = 'memory_warning';
            }
        }

        if ($check->cpu_usage !== null && $check->cpu_usage > 80) {
            $violations[] = 'cpu_warning';
        }

        return $violations;
    }

    public function getHistory(Site $site, int $days = 30): Collection
    {
        return $site->resourceChecks()
            ->where('checked_at', '>=', now()->subDays($days))
            ->orderBy('checked_at')
            ->get();
    }
}
