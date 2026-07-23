<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProspectProfile;
use App\Models\Prospect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prospect>
 */
class ProspectFactory extends Factory
{
    protected $model = Prospect::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'url' => 'https://'.fake()->unique()->domainName(),
            'profile' => fake()->randomElement(ProspectProfile::cases()),
            'contact_name' => fake()->name(),
            'contact_email' => fake()->safeEmail(),
            'notes' => null,
        ];
    }
}
