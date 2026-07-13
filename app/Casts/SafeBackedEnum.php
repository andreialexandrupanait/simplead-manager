<?php

declare(strict_types=1);

namespace App\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a string column to a string-backed enum, but tolerates legacy/unknown
 * values already sitting in the database: instead of throwing a ValueError on
 * read (as Laravel's native enum cast does with Enum::from), an unrecognised
 * value degrades to null via Enum::tryFrom. Writes accept either an enum
 * instance or a raw scalar.
 *
 * @implements CastsAttributes<BackedEnum|null, BackedEnum|string|null>
 */
class SafeBackedEnum implements CastsAttributes
{
    /** @param  class-string<BackedEnum>  $enumClass */
    public function __construct(private string $enumClass) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?BackedEnum
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return $value;
        }

        return $this->enumClass::tryFrom((string) $value);
    }

    /**
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if ($value instanceof BackedEnum) {
            return [$key => (string) $value->value];
        }

        return [$key => (string) $value];
    }
}
