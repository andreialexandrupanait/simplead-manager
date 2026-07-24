<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditRunStatus;
use App\Enums\CrawlSource;
use App\Models\Audit;
use App\Models\AuditRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditRun>
 */
class AuditRunFactory extends Factory
{
    protected $model = AuditRun::class;

    public function definition(): array
    {
        return [
            'audit_id' => Audit::factory(),
            'source' => CrawlSource::SfHeadless,
            'status' => AuditRunStatus::Pending,
            'crawl_dir' => null,
            'started_at' => now(),
            'finished_at' => null,
            'duration_ms' => null,
            'manifest' => null,
            'log' => null,
            'error' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => ['status' => AuditRunStatus::Running]);
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'status' => AuditRunStatus::Done,
            'finished_at' => now(),
            'duration_ms' => 12_000,
            'manifest' => ['present' => 5, 'absent' => 59, 'unmatched' => 1, 'total' => 64],
            'log' => ['Screaming Frog crawl finished.', 'Evaluated 82 checks.'],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => AuditRunStatus::Failed,
            'finished_at' => now(),
            'error' => 'SF boom',
        ]);
    }
}
