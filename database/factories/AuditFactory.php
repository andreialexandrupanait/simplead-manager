<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditStatus;
use App\Models\Audit;
use App\Models\Prospect;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Audit>
 *
 * Defaults to a prospect target (satisfies the site XOR prospect CHECK); use
 * ->forSite($site) to audit a managed site instead.
 */
class AuditFactory extends Factory
{
    protected $model = Audit::class;

    public function definition(): array
    {
        $prospect = Prospect::factory()->create();

        return [
            'site_id' => null,
            'prospect_id' => $prospect->id,
            'status' => AuditStatus::Configurat,
            'url' => $prospect->url,
            'methodology_version' => '2.0',
        ];
    }

    public function forSite(Site $site): static
    {
        return $this->state(fn () => [
            'site_id' => $site->id,
            'prospect_id' => null,
            'url' => $site->url,
        ]);
    }
}
