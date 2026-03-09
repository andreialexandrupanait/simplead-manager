<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server resource monitoring endpoint.
 */
class SAM_Monitoring_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/server-resources', [
            'methods'             => 'GET',
            'callback'            => [$this, 'server_resources'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function server_resources(WP_REST_Request $request): WP_REST_Response {
        $data = [
            'cpu_usage'     => $this->get_cpu_usage(),
            'memory_total'  => $this->get_memory_total(),
            'memory_used'   => $this->get_memory_used(),
            'disk_total'    => $this->get_disk_total(),
            'disk_used'     => $this->get_disk_used(),
            'disk_free'     => $this->get_disk_free(),
            'load_average'  => $this->get_load_average(),
            'uptime'        => $this->get_uptime(),
            'php_memory_limit'  => ini_get('memory_limit'),
            'php_memory_usage'  => memory_get_usage(true),
            'php_memory_peak'   => memory_get_peak_usage(true),
        ];

        return $this->success($data);
    }

    private function get_cpu_usage(): ?float {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            // Approximate CPU usage from 1-minute load average and number of CPUs
            $cpus = $this->get_cpu_count();
            if ($cpus > 0 && $load[0] !== false) {
                return round(($load[0] / $cpus) * 100, 1);
            }
        }
        return null;
    }

    private function get_cpu_count(): int {
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            return substr_count($cpuinfo, 'processor');
        }
        return 1;
    }

    private function get_memory_total(): ?int {
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
                return (int) $m[1] * 1024;
            }
        }
        return null;
    }

    private function get_memory_used(): ?int {
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            $total = 0;
            $available = 0;

            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $total = (int) $m[1] * 1024;
            }
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $available = (int) $m[1] * 1024;
            }

            if ($total > 0) {
                return $total - $available;
            }
        }
        return null;
    }

    private function get_disk_total(): ?int {
        $total = @disk_total_space(ABSPATH);
        return $total !== false ? (int) $total : null;
    }

    private function get_disk_used(): ?int {
        $total = @disk_total_space(ABSPATH);
        $free = @disk_free_space(ABSPATH);
        if ($total !== false && $free !== false) {
            return (int) ($total - $free);
        }
        return null;
    }

    private function get_disk_free(): ?int {
        $free = @disk_free_space(ABSPATH);
        return $free !== false ? (int) $free : null;
    }

    private function get_load_average(): ?array {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return $load !== false ? array_map(fn($v) => round($v, 2), $load) : null;
        }
        return null;
    }

    private function get_uptime(): ?string {
        if (is_readable('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            $seconds = (int) $uptime;
            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return "{$days}d {$hours}h {$minutes}m";
        }
        return null;
    }
}
