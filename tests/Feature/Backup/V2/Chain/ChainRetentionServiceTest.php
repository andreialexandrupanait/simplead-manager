<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Chain;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Retention\ChainRetentionService;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chain-safe retention: the planner never selects a base with a still-in-window incremental, the
 * last valid full, the last verified session, or a protected one — only a genuinely-expired,
 * fully-expired, unanchored chain. Dry-run deletes nothing; an enabled forced run deletes exactly
 * what the dry-run selected.
 */
class ChainRetentionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create();
    }

    public function test_retention_keeps_dependents_last_full_and_protected_selects_only_expired_isolated(): void
    {
        // Old isolated expired full — the only genuinely deletable restore point.
        $oldFull = $this->full(completedDaysAgo: 100, expiresDaysAgo: 70);

        // Protected old expired full — kept because protected.
        $protectedFull = $this->full(completedDaysAgo: 90, expiresDaysAgo: 60, protected: true);

        // Chain whose newest member is still in-window → base kept (dependent not expired).
        $chainBase = $this->full(completedDaysAgo: 80, expiresDaysAgo: 50);
        $incB1 = $this->incremental($chainBase, 1, completedDaysAgo: 79, expiresDaysAgo: 49);
        $incB2 = $this->incremental($chainBase, 2, completedDaysAgo: 2, expiresInDays: 5);

        // Newest full overall, expired + isolated → kept purely as the last valid full.
        $lastFull = $this->full(completedDaysAgo: 10, expiresDaysAgo: 1);

        $service = new ChainRetentionService;
        $plan = $service->plan($this->site);

        // Only the old isolated expired full is selected.
        $this->assertSame([$oldFull->id], $plan->deleteIds());

        // Reasons.
        $this->assertSame('protected', $plan->reasonFor($protectedFull->id));
        $this->assertSame('chain_not_expired', $plan->reasonFor($chainBase->id));
        $this->assertSame('chain_not_expired', $plan->reasonFor($incB1->id));
        $this->assertSame('chain_not_expired', $plan->reasonFor($incB2->id));
        $this->assertSame('last_valid_full', $plan->reasonFor($lastFull->id));

        // Dry-run (default) deletes nothing.
        $service->apply($this->site, force: false);
        $this->assertDatabaseHas('backup_sessions', ['id' => $oldFull->id]);

        // Enabled forced run deletes exactly the selected session, nothing else.
        config(['backup_v2.enabled' => true]);
        $applied = $service->apply($this->site, force: true);
        $this->assertSame([$oldFull->id], $applied->deleteIds());

        $this->assertDatabaseMissing('backup_sessions', ['id' => $oldFull->id]);
        foreach ([$protectedFull, $chainBase, $incB1, $incB2, $lastFull] as $kept) {
            $this->assertDatabaseHas('backup_sessions', ['id' => $kept->id]);
        }
    }

    public function test_retention_keeps_last_verified_chain(): void
    {
        // Newest full (last valid full anchor) so the verified one below is not kept just for that.
        $this->full(completedDaysAgo: 1, expiresDaysAgo: 0);

        // Old, expired, isolated — but the last integrity-verified session → kept.
        $verified = $this->full(completedDaysAgo: 100, expiresDaysAgo: 70, verifiedDaysAgo: 3);

        // Another old expired isolated full, NOT verified → selected.
        $stale = $this->full(completedDaysAgo: 90, expiresDaysAgo: 60);

        $plan = (new ChainRetentionService)->plan($this->site);

        $this->assertContains($stale->id, $plan->deleteIds());
        $this->assertNotContains($verified->id, $plan->deleteIds());
        $this->assertSame('last_verified', $plan->reasonFor($verified->id));
    }

    public function test_forced_run_without_v2_enabled_is_a_noop(): void
    {
        $oldFull = $this->full(completedDaysAgo: 100, expiresDaysAgo: 70);
        $this->full(completedDaysAgo: 1, expiresDaysAgo: 0); // last valid full anchor

        config(['backup_v2.enabled' => false]);
        (new ChainRetentionService)->apply($this->site, force: true);

        // V2 disabled → retention must not mutate storage/rows even when forced.
        $this->assertDatabaseHas('backup_sessions', ['id' => $oldFull->id]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function full(int $completedDaysAgo, ?int $expiresDaysAgo = null, ?int $expiresInDays = null, bool $protected = false, ?int $verifiedDaysAgo = null): BackupSession
    {
        return $this->make('full', null, null, $completedDaysAgo, $expiresDaysAgo, $expiresInDays, $protected, $verifiedDaysAgo);
    }

    private function incremental(BackupSession $base, int $position, int $completedDaysAgo, ?int $expiresDaysAgo = null, ?int $expiresInDays = null, bool $protected = false, ?int $verifiedDaysAgo = null): BackupSession
    {
        return $this->make('incremental', $base->id, $position, $completedDaysAgo, $expiresDaysAgo, $expiresInDays, $protected, $verifiedDaysAgo);
    }

    private function make(string $type, ?int $baseId, ?int $position, int $completedDaysAgo, ?int $expiresDaysAgo, ?int $expiresInDays, bool $protected, ?int $verifiedDaysAgo): BackupSession
    {
        $expiresAt = null;
        if ($expiresDaysAgo !== null) {
            $expiresAt = now()->subDays($expiresDaysAgo);
        } elseif ($expiresInDays !== null) {
            $expiresAt = now()->addDays($expiresInDays);
        }

        return BackupSession::create([
            'site_id' => $this->site->id,
            'type' => $type,
            'scope' => ['files' => true, 'database' => false],
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Completed,
            'full_base_id' => $baseId,
            'chain_position' => $position,
            'protected' => $protected,
            'verified_at' => $verifiedDaysAgo !== null ? now()->subDays($verifiedDaysAgo) : null,
            'expires_at' => $expiresAt,
            'completed_at' => now()->subDays($completedDaysAgo),
            'idempotency_key' => 'ret-'.uniqid('', true),
            'format_version' => 'simplead-backup/1',
        ]);
    }
}
