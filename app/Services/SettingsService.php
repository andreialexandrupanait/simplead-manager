<?php

namespace App\Services;

use App\Models\AppSetting;

class SettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        $setting = AppSetting::where('key', $key)->first();

        if (!$setting) {
            return $default;
        }

        return $this->castValue($setting->value, $setting->type);
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
    }

    public function getGroup(string $group): array
    {
        return AppSetting::where('group', $group)
            ->get()
            ->mapWithKeys(fn (AppSetting $s) => [$s->key => $this->castValue($s->value, $s->type)])
            ->toArray();
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
