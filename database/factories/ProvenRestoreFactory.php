<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProvenRestore;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProvenRestore>
 */
class ProvenRestoreFactory extends Factory
{
    protected $model = ProvenRestore::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'backup_id' => null,
            'status' => ProvenRestore::STATUS_PASSED,
            'checks' => ['homepage_200' => true, 'login_reachable' => true, 'loopback_ok' => true, 'db_coherent' => true],
            'error' => null,
            'ran_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => ProvenRestore::STATUS_FAILED,
            'checks' => ['homepage_200' => false],
            'error' => 'homepage did not return 200',
        ]);
    }
}
