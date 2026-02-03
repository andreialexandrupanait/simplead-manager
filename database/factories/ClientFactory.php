<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            "name" => fake()->company(),
            "email" => fake()->companyEmail(),
            "phone" => fake()->phoneNumber(),
            "company" => fake()->company(),
            "is_active" => true,
        ];
    }
}
