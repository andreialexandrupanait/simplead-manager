<?php

namespace App\DTOs;

readonly class DashboardStats
{
    public function __construct(
        public int $sitesDown,
        public ?float $avgUptime,
        public ?int $avgResponseTime,
        public int $pendingUpdates,
        public int $pendingPluginUpdates,
        public int $pendingThemeUpdates,
        public int $pendingCoreUpdates,
        public int $failedBackups,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            sitesDown: $data['sites_down'],
            avgUptime: $data['avg_uptime'],
            avgResponseTime: $data['avg_response_time'],
            pendingUpdates: $data['pending_updates'],
            pendingPluginUpdates: $data['pending_plugin_updates'],
            pendingThemeUpdates: $data['pending_theme_updates'],
            pendingCoreUpdates: $data['pending_core_updates'],
            failedBackups: $data['failed_backups'],
        );
    }

    public function toArray(): array
    {
        return [
            'sites_down' => $this->sitesDown,
            'avg_uptime' => $this->avgUptime,
            'avg_response_time' => $this->avgResponseTime,
            'pending_updates' => $this->pendingUpdates,
            'pending_plugin_updates' => $this->pendingPluginUpdates,
            'pending_theme_updates' => $this->pendingThemeUpdates,
            'pending_core_updates' => $this->pendingCoreUpdates,
            'failed_backups' => $this->failedBackups,
        ];
    }
}
