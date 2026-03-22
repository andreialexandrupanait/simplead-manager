<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const CACHE_TTL = 300; // 5 minutes

    public function get(string $key, mixed $default = null): mixed
    {
        $cached = Cache::remember("settings.{$key}", self::CACHE_TTL, function () use ($key) {
            $setting = AppSetting::where('key', $key)->first();
            if (! $setting) {
                return ['_null' => true];
            }

            return ['value' => $setting->value, 'type' => $setting->type];
        });

        if (isset($cached['_null'])) {
            return $default;
        }

        return $this->castValue($cached['value'], $cached['type']);
    }

    public function set(string $key, mixed $value, string $group = 'general', string $type = 'string'): void
    {
        $serialized = $this->serializeValue($value, $type);

        AppSetting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $serialized,
                'group' => $group,
                'type' => $type,
            ]
        );

        Cache::forget("settings.{$key}");
        Cache::forget("settings.group.{$group}");
    }

    public function getGroup(string $group): array
    {
        return Cache::remember("settings.group.{$group}", self::CACHE_TTL, function () use ($group) {
            return AppSetting::where('group', $group)
                ->get()
                ->mapWithKeys(fn (AppSetting $s) => [$s->key => $this->castValue($s->value, $s->type)])
                ->toArray();
        });
    }

    protected function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    protected function serializeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string) $value,
            'json' => json_encode($value),
            default => (string) $value,
        };
    }
}
